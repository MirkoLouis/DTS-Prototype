<?php

namespace Tests\Support;

use App\Common\Config\Config;

/**
 * Temporary files and folders for tests, all under working-php/.test/tmp.
 *
 * Tests never use the system temp directory: TestLifecycle removes the .test folder when a run
 * starts and ends, so nothing a test creates is left behind or mixed with other programs' files.
 */
final class TestFiles
{
    /**
     * Create and return a fresh, empty directory.
     */
    public static function dir(string $prefix): string
    {
        $path = self::root() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
        mkdir($path, 0777, true);

        return $path;
    }

    /**
     * Return a path for a new file (the file itself is not created).
     */
    public static function file(string $prefix): string
    {
        return self::root() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
    }

    /**
     * The .test/tmp folder, created on first use.
     */
    private static function root(): string
    {
        $root = Config::testPath('tmp');
        if (!is_dir($root)) {
            mkdir($root, 0777, true);
        }

        return $root;
    }
}
