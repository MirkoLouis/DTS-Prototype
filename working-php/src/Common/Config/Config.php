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
}
