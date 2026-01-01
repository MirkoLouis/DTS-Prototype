<?php

namespace Tests\Integrity;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use App\Models\DocumentLog;

class IntegrityCheckTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the integrity check passes on a clean database.
     */
    public function testIntegrityCheckPassesOnCleanDatabase()
    {
        // Run migrations and seed the database to ensure a clean state
        Artisan::call('migrate:fresh --seed');

        // Run the integrity check
        Artisan::call('dts:verify-integrity');

        // Retrieve the result from cache
        $result = Cache::get('integrity-check-result');

        // Assert that the integrity check passed
        $this->assertNotNull($result);
        $this->assertEquals(100, $result['verified_percentage']);
        $this->assertEquals(0, $result['invalid_logs']);
        $this->assertEmpty($result['mismatched_ids']);
    }

    /**
     * Test that the integrity check fails when a log is corrupted.
     */
    public function testIntegrityCheckFailsWhenLogIsCorrupted()
    {
        // Run migrations and seed the database
        Artisan::call('migrate:fresh --seed');

        // Get a log to corrupt
        $logToCorrupt = DocumentLog::inRandomOrder()->first();
        $this->assertNotNull($logToCorrupt, 'No document logs found to corrupt. Seeder might not be working.');

        // Corrupt the log
        Artisan::call('dts:corrupt-log', ['logId' => $logToCorrupt->id]);

        // Run the integrity check again
        Artisan::call('dts:verify-integrity');

        // Retrieve the result from cache
        $result = Cache::get('integrity-check-result');

        // Assert that the integrity check failed
        $this->assertNotNull($result);
        $this->assertLessThan(100, $result['verified_percentage']);
        $this->assertGreaterThan(0, $result['invalid_logs']);
        $this->assertContains($logToCorrupt->id, $result['mismatched_ids']);
    }
}
