<?php

namespace Tests\Fixtures\ResultsSuite;

use PHPUnit\Framework\TestCase;

/**
 * Three tiny tests with known outcomes; run only by ResultsExtensionTest through its own phpunit.xml.
 */
class OutcomesFixtureTest extends TestCase
{
    // Passes
    public function test_passes(): void
    {
        $this->assertTrue(true);
    }

    // Fails on purpose
    public function test_fails(): void
    {
        $this->assertSame(1, 2, 'intentional failure');
    }

    // Skipped on purpose
    public function test_skipped(): void
    {
        $this->markTestSkipped('intentional skip');
    }
}
