<?php
declare(strict_types=1);

/**
 * Lightweight application bootstrap.
 *
 * No Composer dependency is required. Classes under the CvTailor namespace
 * are loaded from the app directory using this small PSR-4-style autoloader.
 */

define('CV_TAILOR_BASE_PATH', dirname(__DIR__));
define('CV_TAILOR_APP_PATH', __DIR__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'CvTailor\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = CV_TAILOR_APP_PATH . '/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

/**
 * Load and cache a configuration file from app/Config.
 *
 * @return array<string,mixed>
 */
function cv_config(string $name): array
{
    static $cache = [];

    if (isset($cache[$name])) {
        return $cache[$name];
    }

    if (!preg_match('/^[a-z0-9_-]+$/i', $name)) {
        throw new InvalidArgumentException('Invalid configuration name.');
    }

    $path = CV_TAILOR_APP_PATH . '/Config/' . $name . '.php';

    if (!is_file($path)) {
        throw new RuntimeException('Configuration file not found: ' . $name);
    }

    $config = require $path;

    if (!is_array($config)) {
        throw new RuntimeException('Configuration file must return an array: ' . $name);
    }

    $cache[$name] = $config;
    return $config;
}

$appConfig = cv_config('app');
date_default_timezone_set((string) ($appConfig['timezone'] ?? 'UTC'));
