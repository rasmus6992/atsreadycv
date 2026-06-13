<?php
declare(strict_types=1);

namespace CvTailor\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class RateLimiter
{
    private int $maxAttempts;
    private int $windowSeconds;
    private string $hashSecret;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $pdo,
        array $config
    ) {
        $this->maxAttempts = max(1, (int) ($config['max_attempts'] ?? 5));
        $this->windowSeconds = max(60, (int) ($config['window_seconds'] ?? 3600));
        $this->hashSecret = (string) ($config['hash_secret'] ?? '');

        if ($this->hashSecret === '') {
            throw new \RuntimeException('The rate-limit hash secret is not configured.');
        }
    }

    /**
     * Reserve one request for the supplied IP address.
     *
     * @return array{allowed:bool,limit:int,used:int,remaining:int,limited:bool,retry_after:int,reset_at:?string}
     */
    public function consume(string $ipAddress): array
    {
        $now = $this->now();
        $ipHash = $this->hashIp($ipAddress);

        try {
            $this->pdo->beginTransaction();

            // Ensure the row exists before locking it. INSERT IGNORE is safe under concurrency.
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO cv_tailor_rate_limits
                    (ip_hash, attempt_count, window_started_at, last_attempt_at)
                 VALUES
                    (:ip_hash, 0, :window_started_at, :last_attempt_at)'
            );
            $insert->execute([
                ':ip_hash' => $ipHash,
                ':window_started_at' => $this->toSqlDate($now),
                ':last_attempt_at' => $this->toSqlDate($now),
            ]);

            $select = $this->pdo->prepare(
                'SELECT attempt_count, window_started_at
                 FROM cv_tailor_rate_limits
                 WHERE ip_hash = :ip_hash
                 LIMIT 1
                 FOR UPDATE'
            );
            $select->execute([':ip_hash' => $ipHash]);
            $row = $select->fetch();

            $attemptCount = is_array($row) ? (int) ($row['attempt_count'] ?? 0) : 0;
            $windowStartedAt = is_array($row) && isset($row['window_started_at'])
                ? $this->fromSqlDate((string) $row['window_started_at'])
                : $now;

            $resetAt = $windowStartedAt->modify('+' . $this->windowSeconds . ' seconds');

            if ($now >= $resetAt) {
                $attemptCount = 0;
                $windowStartedAt = $now;
                $resetAt = $now->modify('+' . $this->windowSeconds . ' seconds');
            }

            if ($attemptCount >= $this->maxAttempts) {
                $touch = $this->pdo->prepare(
                    'UPDATE cv_tailor_rate_limits
                     SET last_attempt_at = :last_attempt_at
                     WHERE ip_hash = :ip_hash'
                );
                $touch->execute([
                    ':last_attempt_at' => $this->toSqlDate($now),
                    ':ip_hash' => $ipHash,
                ]);

                $this->pdo->commit();

                return $this->result(false, $attemptCount, $resetAt, $now);
            }

            $attemptCount++;

            $update = $this->pdo->prepare(
                'UPDATE cv_tailor_rate_limits
                 SET attempt_count = :attempt_count,
                     window_started_at = :window_started_at,
                     last_attempt_at = :last_attempt_at
                 WHERE ip_hash = :ip_hash'
            );
            $update->execute([
                ':attempt_count' => $attemptCount,
                ':window_started_at' => $this->toSqlDate($windowStartedAt),
                ':last_attempt_at' => $this->toSqlDate($now),
                ':ip_hash' => $ipHash,
            ]);

            $this->pdo->commit();

            return $this->result(true, $attemptCount, $resetAt, $now);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Read current status without consuming an attempt.
     *
     * @return array{allowed:bool,limit:int,used:int,remaining:int,limited:bool,retry_after:int,reset_at:?string}
     */
    public function status(string $ipAddress): array
    {
        $now = $this->now();
        $statement = $this->pdo->prepare(
            'SELECT attempt_count, window_started_at
             FROM cv_tailor_rate_limits
             WHERE ip_hash = :ip_hash
             LIMIT 1'
        );
        $statement->execute([':ip_hash' => $this->hashIp($ipAddress)]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return [
                'allowed' => true,
                'limit' => $this->maxAttempts,
                'used' => 0,
                'remaining' => $this->maxAttempts,
                'limited' => false,
                'retry_after' => 0,
                'reset_at' => null,
            ];
        }

        $attemptCount = (int) ($row['attempt_count'] ?? 0);
        $windowStartedAt = $this->fromSqlDate((string) ($row['window_started_at'] ?? ''));
        $resetAt = $windowStartedAt->modify('+' . $this->windowSeconds . ' seconds');

        if ($now >= $resetAt) {
            return [
                'allowed' => true,
                'limit' => $this->maxAttempts,
                'used' => 0,
                'remaining' => $this->maxAttempts,
                'limited' => false,
                'retry_after' => 0,
                'reset_at' => null,
            ];
        }

        return $this->result(
            $attemptCount < $this->maxAttempts,
            $attemptCount,
            $resetAt,
            $now
        );
    }

    /**
     * @return array{allowed:bool,limit:int,used:int,remaining:int,limited:bool,retry_after:int,reset_at:?string}
     */
    private function result(
        bool $allowed,
        int $attemptCount,
        DateTimeImmutable $resetAt,
        DateTimeImmutable $now
    ): array {
        $remaining = max(0, $this->maxAttempts - $attemptCount);
        $retryAfter = max(0, $resetAt->getTimestamp() - $now->getTimestamp());
        $limited = $remaining === 0 && $retryAfter > 0;

        return [
            'allowed' => $allowed,
            'limit' => $this->maxAttempts,
            'used' => min($attemptCount, $this->maxAttempts),
            'remaining' => $remaining,
            'limited' => $limited,
            'retry_after' => $limited ? $retryAfter : 0,
            'reset_at' => $resetAt->format(DATE_ATOM),
        ];
    }

    private function hashIp(string $ipAddress): string
    {
        return hash_hmac('sha256', $ipAddress, $this->hashSecret);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function toSqlDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function fromSqlDate(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $date,
            new DateTimeZone('UTC')
        );

        return $parsed instanceof DateTimeImmutable ? $parsed : $this->now();
    }
}
