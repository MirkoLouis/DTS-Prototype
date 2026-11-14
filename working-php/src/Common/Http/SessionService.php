<?php

namespace App\Common\Http;

use App\Common\Config\Config;

/**
 * Starts the PHP session for a web request.
 *
 * Sessions live in the app's own folder, not the folder every PHP app on the server shares: other
 * apps cannot read or clear them, and the seeders can reset this app's sessions without touching
 * anyone else's. Because the operating system only cleans its own session folder, the app turns
 * PHP's session garbage collection on for this one.
 */
class SessionService
{
    /**
     * The folder session files are kept in, created when missing.
     */
    public static function directory(): string
    {
        $directory = Config::isTesting() ? Config::testPath('sessions') : BASE_PATH . '/storage/sessions';
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }

        return $directory;
    }

    /**
     * Configure and start the session with hardened cookie settings.
     */
    public static function start(): void
    {
        session_save_path(self::directory());

        // A session idle for longer than this is removed; about one request in a hundred does the cleaning
        ini_set('session.gc_maxlifetime', (string) self::lifetime());
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');

        session_start([
            'cookie_httponly' => true,
            'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'cookie_samesite' => 'Strict',
            'use_strict_mode' => true,
            'use_only_cookies' => true,
        ]);
    }

    /**
     * Seconds a session may stay idle (SESSION_LIFETIME), never less than a minute.
     */
    public static function lifetime(): int
    {
        return max(60, (int) Config::get('app', 'session_lifetime', 43200));
    }
}
