<?php

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Tests\Support\TestDatabase;

/**
 * Base class: every test starts from a freshly rebuilt, seeded test database.
 */
abstract class TestCase extends BaseTestCase
{
    // ['users' => [username => id], 'departments' => [name => id], 'purposes' => [name => id]]
    protected array $fx = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Empty the isolated test response cache so no stale page is served
        foreach (glob(BASE_PATH . '/cache/responses-test/*') ?: [] as $cached) {
            @unlink($cached);
        }

        // Rebuild the schema and seed the baseline rows
        TestDatabase::reset();
        $this->fx = TestDatabase::seedBaseline();
    }
}
