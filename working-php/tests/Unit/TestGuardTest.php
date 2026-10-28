<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestGuard;

class TestGuardTest extends TestCase
{
    // The operational database name must be refused even in the testing env
    public function test_refuses_operational_database(): void
    {
        $this->expectException(RuntimeException::class);
        TestGuard::assertSafe('testing', 'deped_dts');
    }

    // A non-testing environment must be refused even for a _test database
    public function test_refuses_non_testing_env(): void
    {
        $this->expectException(RuntimeException::class);
        TestGuard::assertSafe('production', 'deped_dts_test');
    }

    // The testing env with a _test database is the only accepted combination
    public function test_accepts_test_database(): void
    {
        TestGuard::assertSafe('testing', 'deped_dts_test');
        $this->addToAssertionCount(1);
    }
}
