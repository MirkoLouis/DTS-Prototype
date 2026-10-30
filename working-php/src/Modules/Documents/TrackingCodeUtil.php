<?php

namespace App\Modules\Documents;

use App\Common\Config\Config;

/**
 * Single source of tracking codes for the app and the seeders.
 * Format: DEPED- plus 10 uppercase hex characters (40 bits) from the OS CSPRNG.
 * Uniqueness is enforced by the UNIQUE index on documents.tracking_code; callers retry on MySQL 1062.
 */
class TrackingCodeUtil
{
    public const PATTERN = '/^DEPED-[0-9A-F]{10}$/';

    // Test-only code source; returning null falls through to the CSPRNG. Honoured only when app.env is testing.
    private static $testOverride = null;

    // Whether the DTS_TEST_FORCE_DUPLICATE env var has been inspected in this process
    private static bool $seamChecked = false;

    /**
     * Generate one code from random_bytes(); no clock or counter input.
     * In production this costs one getenv() per process on top of random_bytes().
     */
    public static function generate(): string
    {
        // Inspect the env-var seam once per process
        if (!self::$seamChecked) {
            self::$seamChecked = true;
            self::installForcedDuplicateSeam();
        }

        // Consult the test override only while the configuration says testing
        if (self::$testOverride !== null && Config::get('app', 'env') === 'testing') {
            $code = (self::$testOverride)();
            if ($code !== null) {
                return $code;
            }
        }

        return self::random();
    }

    /**
     * Install (or clear with null) a test-only code source. generate() ignores it unless app.env is testing.
     */
    public static function setTestOverride(?callable $source): void
    {
        self::$seamChecked = true;
        self::$testOverride = $source;
    }

    /**
     * Draw a fresh code from the OS CSPRNG.
     */
    private static function random(): string
    {
        // 5 random bytes encode to exactly 10 hex characters
        return 'DEPED-' . strtoupper(bin2hex(random_bytes(5)));
    }

    /**
     * DTS_TEST_FORCE_DUPLICATE=1 under app.env=testing: the first two codes of the process are identical,
     * so a seeder's first chunk hits MySQL 1062 and must take its retry path.
     */
    private static function installForcedDuplicateSeam(): void
    {
        // Do nothing unless both the env var and the testing environment are present
        if (getenv('DTS_TEST_FORCE_DUPLICATE') !== '1' || Config::get('app', 'env') !== 'testing') {
            return;
        }

        $first = null;
        $served = 0;
        // Captured by reference so the counter and the first code persist across calls to the closure
        self::$testOverride = static function () use (&$first, &$served): ?string {
            // After the duplicate pair, hand control back to the CSPRNG
            if ($served >= 2) {
                return null;
            }
            $served++;
            $first ??= self::random();
            return $first;
        };
    }
}
