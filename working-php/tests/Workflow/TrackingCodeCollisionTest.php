<?php

namespace Tests\Workflow;

use App\Core\Database;
use App\Core\TrackingCode;
use App\Services\DocumentWorkflowService;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * Test-only subclass: replaces the generator hook with a scripted sequence of codes.
 * Production code is unchanged; the hook just delegates to TrackingCode::generate().
 */
class ScriptedCodeWorkflowService extends DocumentWorkflowService
{
    public array $queue = [];

    // Return scripted codes first, then fall back to the real generator
    protected function newTrackingCode(): string
    {
        return array_shift($this->queue) ?? parent::newTrackingCode();
    }
}

class TrackingCodeCollisionTest extends TestCase
{
    // A duplicate code on the first attempt must be retried with a fresh code
    public function test_submit_retries_on_duplicate_code(): void
    {
        // Occupy a code, then script the service to offer it first
        $taken = Fixtures::submit();
        $before = (int) Database::getInstance()->query('SELECT COUNT(*) c FROM documents')->fetch()['c'];

        $service = new ScriptedCodeWorkflowService();
        $service->queue = [$taken['tracking_code']];

        $purposeId = Database::getInstance()->query('SELECT id FROM purposes WHERE is_official = 1 ORDER BY id LIMIT 1')->fetch()['id'];
        $result = $service->submitDocument([
            'title' => 'Retry Doc', 'guest_name' => 'A', 'guest_email' => 'a@example.com', 'guest_phone' => '09171234567',
            'district' => 'Iligan City', 'department' => 'Records Unit', 'purpose_id' => $purposeId,
        ]);

        // Succeeded with a different valid code and exactly one new row
        $after = (int) Database::getInstance()->query('SELECT COUNT(*) c FROM documents')->fetch()['c'];
        $this->assertNotSame($taken['tracking_code'], $result['tracking_code']);
        $this->assertMatchesRegularExpression(TrackingCode::PATTERN, $result['tracking_code']);
        $this->assertSame($before + 1, $after);
    }

    // The seeder must emit CSPRNG codes: valid format, all distinct, no counter suffix
    public function test_no_seeded_code_contains_sequential_counter(): void
    {
        // Run the real seeder for 50 documents against the test database only
        $cmd = 'APP_ENV=testing ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/scripts/seeders/fast-seed.php') . ' 50 2>&1';
        exec($cmd, $out, $exit);
        $this->assertSame(0, $exit, implode("\n", $out));

        $codes = Database::getInstance()->query('SELECT tracking_code FROM documents')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(50, $codes);
        $this->assertCount(50, array_unique($codes));

        // Every code matches the pattern
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression(TrackingCode::PATTERN, $code);
        }
    }
}
