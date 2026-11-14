<?php

namespace App\Common\Config;

/**
 * Read-only accessor for values parsed from .env by src/Common/Config/app.config.php.
 */
class Config
{
    private static ?array $config = null;

    /**
     * Return a configuration value, loading config.php once per process.
     */
    public static function get(string $section, string $key, $default = null)
    {
        // Lazily load and memoize the parsed configuration array
        if (self::$config === null) {
            self::$config = require BASE_PATH . '/src/Common/Config/app.config.php';
        }

        return self::$config[$section][$key] ?? $default;
    }

    /**
     * Path of a file or folder that only exists while tests run.
     *
     * Everything a test creates lives under working-php/.test/, so a test can never read, overwrite or
     * delete what the real app keeps (cache, checkpoints, backups, logs).
     */
    public static function testPath(string $relative): string
    {
        return BASE_PATH . '/.test/' . ltrim($relative, '/');
    }

    /**
     * Whether the app is running under the test suite (APP_ENV=testing).
     */
    public static function isTesting(): bool
    {
        return self::get('app', 'env') === 'testing';
    }
}
