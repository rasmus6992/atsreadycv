<?php
declare(strict_types=1);

namespace CvTailor\Database;

use PDO;
use PDOException;
use RuntimeException;

final class Connection
{
    /**
     * @param array<string,mixed> $config
     */
    public static function make(array $config): PDO
    {
        $host = (string) ($config['host'] ?? 'localhost');
        $name = (string) ($config['name'] ?? '');
        $user = (string) ($config['user'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $charset = (string) ($config['charset'] ?? 'utf8mb4');

        $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";

        try {
            return new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            error_log('Database connection failed: ' . $exception->getMessage());
            throw new RuntimeException(
                'A database connection error occurred. Please check the server configuration.',
                0,
                $exception
            );
        }
    }
}
