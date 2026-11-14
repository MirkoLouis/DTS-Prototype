<?php

namespace Tests\Support;

use App\Common\Config\Config;
use App\Common\Middleware\CacheMiddleware;
use App\Modules\Integrity\Jobs\ChainCheckpointJob;
use PDO;
use RuntimeException;

/**
 * Gives every suite run a fresh test database and fresh -test artifacts.
 * The drop is destructive, so TestGuard runs immediately before it and the name comes only from the testing config.
 */
class TestLifecycle
{
    /**
     * Drop and recreate the test database, then reset the -test artifacts.
     */
    public static function begin(array $dbConfig): void
    {
        // Guard, then drop and create over a server-level connection
        self::dropDatabase($dbConfig);
        $name = (string) $dbConfig['dbname'];
        self::serverPdo($dbConfig)->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4');

        // Start from empty artifact locations (directories are recreated, the result file is not)
        self::removeArtifacts();
        mkdir(CacheMiddleware::cacheDir(), 0755, true);
        mkdir(ChainCheckpointJob::directory(), 0755, true);
    }

    /**
     * Drop the test database and remove the .test folder.
     */
    public static function end(array $dbConfig): void
    {
        self::dropDatabase($dbConfig);
        self::removeArtifacts();
    }

    /**
     * Run the guard and drop the database in one step, so no drop can bypass the guard.
     */
    private static function dropDatabase(array $dbConfig): void
    {
        // The guard must pass for this exact name immediately before the drop
        $name = (string) $dbConfig['dbname'];
        TestGuard::assertSafe('testing', $name);
        self::serverPdo($dbConfig)->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    }

    /**
     * Connect to the server with no database selected.
     */
    private static function serverPdo(array $c): PDO
    {
        return new PDO(
            "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4",
            $c['user'],
            $c['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * Delete the .test folder, which holds every file the suite creates; refuses any other path.
     */
    private static function removeArtifacts(): void
    {
        // The path comes from the app's own helper and must be the .test folder inside the project
        $path = rtrim(Config::testPath(''), '/');
        if ($path !== BASE_PATH . '/.test') {
            throw new RuntimeException("Refusing to delete a path that is not the .test folder: {$path}");
        }
        self::removePath($path);
    }

    /**
     * Remove a file, or a directory with everything inside it.
     */
    private static function removePath(string $path): void
    {
        // Nothing to do when absent
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        // Files and symlinks are unlinked; directories are emptied first
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removePath($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
