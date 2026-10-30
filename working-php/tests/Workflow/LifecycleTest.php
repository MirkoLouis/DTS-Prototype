<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Modules\Integrity\Jobs\IntegrityCheckJob;
use App\Modules\Documents\DocumentWorkflowService;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

class LifecycleTest extends TestCase
{
    // Fetch the document row by id
    private function doc(int $id): array
    {
        return Database::getInstance()->query('SELECT * FROM documents WHERE id = :id', [':id' => $id])->fetch();
    }

    // Count the log rows of a document
    private function logCount(int $id): int
    {
        return (int) Database::getInstance()->query('SELECT COUNT(*) c FROM document_logs WHERE document_id = :id', [':id' => $id])->fetch()['c'];
    }

    // Walk the whole route and check the code never changes, the logs are complete and the chain verifies
    public function test_full_lifecycle_keeps_one_tracking_code_and_verifies(): void
    {
        $service = new DocumentWorkflowService();
        $pin = TestDatabase::PIN;
        $records = Fixtures::user('records.unit');
        $cash = Fixtures::user('cash.unit');
        $personnel = Fixtures::user('personnel.unit');

        // Submit and remember the code
        $submitted = Fixtures::submit();
        $id = $submitted['id'];
        $code = $submitted['tracking_code'];
        $this->assertSame($code, $this->doc($id)['tracking_code']);

        // Each step: perform it, then assert the code is unchanged
        $steps = [
            fn() => $service->finalizeIntake($id, ['Cash Unit', 'Personnel Unit'], $records, $pin),
            fn() => $service->scanDocument($code, $cash, $pin),
            fn() => $service->completeTask($id, '', $cash, $pin),
            fn() => $service->scanDocument($code, $personnel, $pin),
            fn() => $service->completeTask($id, '', $personnel, $pin),
            fn() => $service->scanDocument($code, $records, $pin),
            fn() => $service->releaseDocument($id, $records, $pin),
        ];
        foreach ($steps as $step) {
            $step();
            $this->assertSame($code, $this->doc($id)['tracking_code']);
        }

        // Final state and log count: submitted, accepted, 2x(received, complete), ready, released
        $this->assertSame('completed', $this->doc($id)['status']);
        $this->assertSame(8, $this->logCount($id));

        // Run the full integrity audit and read its verified percentage
        $db = Database::getInstance();
        $checkId = '00000000-0000-4000-8000-000000000001';
        $db->query("INSERT INTO integrity_checks (id, user_id, status, created_at, updated_at) VALUES (:id, :u, 'queued', NOW(), NOW())", [
            ':id' => $checkId,
            ':u' => $this->fx['users']['admin'],
        ]);
        (new IntegrityCheckJob($checkId))->handle();
        $row = $db->query('SELECT status, results FROM integrity_checks WHERE id = :id', [':id' => $checkId])->fetch();
        $results = json_decode($row['results'], true);
        $this->assertSame(100.0, (float) $results['verified_percentage']);
    }

    // Scan as a user; return the exception message the workflow raised
    private function scanError(string $code, string $username): string
    {
        try {
            (new DocumentWorkflowService())->scanDocument($code, Fixtures::user($username), TestDatabase::PIN);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
        $this->fail('Expected the scan to be rejected.');
    }

    // A department that is not next in the route cannot receive the document
    public function test_wrong_department_scan_is_rejected(): void
    {
        $submitted = Fixtures::submit();
        Fixtures::advanceTo($submitted['id'], 'in_transit', ['Cash Unit']);
        $before = $this->logCount($submitted['id']);

        // Staff of another department are stopped by the policy before the scan logic runs
        $this->assertStringContainsString('Action Denied', $this->scanError($submitted['tracking_code'], 'personnel.unit'));

        // The Records officer is bound by the same step rule, so the policy denies it too
        $this->assertStringContainsString('Action Denied', $this->scanError($submitted['tracking_code'], 'records.unit'));

        // Neither attempt may change the document or its chain
        $this->assertSame('in_transit', $this->doc($submitted['id'])['status']);
        $this->assertSame($before, $this->logCount($submitted['id']));
    }
}
