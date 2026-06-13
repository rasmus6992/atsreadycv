<?php
declare(strict_types=1);

namespace CvTailor\Support;

final class Security
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');

        if (self::isHttps()) {
            ini_set('session.cookie_secure', '1');
        }

        session_start();
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrfToken(mixed $submittedToken): bool
    {
        return is_string($submittedToken)
            && isset($_SESSION['csrf_token'])
            && is_string($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $submittedToken);
    }

    public static function clientIp(bool $trustCloudflareHeader = false): string
    {
        if ($trustCloudflareHeader) {
            $cloudflareIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';

            if (is_string($cloudflareIp) && filter_var($cloudflareIp, FILTER_VALIDATE_IP)) {
                return strtolower($cloudflareIp);
            }
        }

        $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';

        if (is_string($remoteAddress) && filter_var($remoteAddress, FILTER_VALIDATE_IP)) {
            return strtolower($remoteAddress);
        }

        // A deterministic fallback keeps malformed/missing addresses in one limited bucket.
        return '0.0.0.0';
    }

    public static function authorizeDownload(int $transactionId, int $maximumIds = 20): void
    {
        $authorizedIds = $_SESSION['authorized_download_ids'] ?? [];
        $authorizedIds = is_array($authorizedIds) ? array_map('intval', $authorizedIds) : [];
        $authorizedIds[] = $transactionId;
        $authorizedIds = array_values(array_unique($authorizedIds));

        if (count($authorizedIds) > $maximumIds) {
            $authorizedIds = array_slice($authorizedIds, -$maximumIds);
        }

        $_SESSION['authorized_download_ids'] = $authorizedIds;
    }

    public static function canDownload(int $transactionId): bool
    {
        $authorizedIds = $_SESSION['authorized_download_ids'] ?? [];
        $authorizedIds = is_array($authorizedIds) ? array_map('intval', $authorizedIds) : [];

        return in_array($transactionId, $authorizedIds, true);
    }

    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        return isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
    }
}
