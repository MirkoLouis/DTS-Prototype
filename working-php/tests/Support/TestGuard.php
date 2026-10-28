<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Last line of defence against running the suite on operational data.
 */
class TestGuard
{
    /**
     * Throw unless the environment is 'testing' and the database name ends in _test.
     */
    public static function assertSafe(string $env, string $dbName): void
    {
        // Both conditions must hold; either one alone is not enough to prove the target is disposable
        if ($env !== 'testing' || !str_ends_with($dbName, '_test')) {
            throw new RuntimeException('Refusing to run: tests need APP_ENV=testing and a database whose name ends in _test.');
        }
    }
}
