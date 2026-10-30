<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Services\DocumentWorkflowService;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Officers are bound by the same department-step rule as staff when processing documents.
 */
class OfficerStepRuleTest extends TestCase
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

    // Run a callable and return the exception message it raised
    private function errorOf(callable $action): string
    {
        try {
            $action();
        } catch (\Exception $e) {
            return $e->getMessage();
        }
        $this->fail('Expected the action to be rejected.');
    }

    // An officer cannot receive a document waiting at another department's step
    public function test_officer_cannot_receive_document_at_another_department_step(): void
    {
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'in_transit', ['Cash Unit']);
        $before = $this->logCount($s['id']);

        $message = $this->errorOf(fn () => (new DocumentWorkflowService())->scanDocument($s['tracking_code'], Fixtures::user('records.unit'), TestDatabase::PIN));

        // The policy denies it, and nothing changes
        $this->assertStringContainsString('Action Denied', $message);
        $this->assertSame('in_transit', $this->doc($s['id'])['status']);
        $this->assertSame($before, $this->logCount($s['id']));
    }

    // An officer cannot complete a task that sits at another department's step
    public function test_officer_cannot_complete_task_at_another_department_step(): void
    {
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'processing', ['Cash Unit']);
        $before = $this->logCount($s['id']);

        $message = $this->errorOf(fn () => (new DocumentWorkflowService())->completeTask($s['id'], '', Fixtures::user('records.unit'), TestDatabase::PIN));

        $this->assertStringContainsString('Action Denied', $message);
        $this->assertSame('processing', $this->doc($s['id'])['status']);
        $this->assertSame($before, $this->logCount($s['id']));
    }

    // An officer can process when Records Unit is the current step
    public function test_officer_can_process_when_records_unit_is_the_current_step(): void
    {
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'in_transit', ['Records Unit', 'Cash Unit']);
        $service = new DocumentWorkflowService();
        $officer = Fixtures::user('records.unit');

        $service->scanDocument($s['tracking_code'], $officer, TestDatabase::PIN);
        $this->assertSame('processing', $this->doc($s['id'])['status']);

        $service->completeTask($s['id'], '', $officer, TestDatabase::PIN);
        $this->assertSame('in_transit', $this->doc($s['id'])['status']);
        $this->assertSame(2, (int) $this->doc($s['id'])['current_step']);
    }

    // The final Records receive works after the route ends, even when Records was a middle step
    public function test_officer_final_receive_after_route_complete(): void
    {
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'ready_for_release', ['Cash Unit', 'Records Unit', 'Personnel Unit']);

        $this->assertSame('ready_for_release', $this->doc($s['id'])['status']);
    }

    // Intake and release stay with the officer
    public function test_officer_can_still_finalize_decline_and_release(): void
    {
        $a = Fixtures::submit();
        Fixtures::advanceTo($a['id'], 'in_transit', ['Cash Unit']);
        $this->assertSame('in_transit', $this->doc($a['id'])['status']);

        $b = Fixtures::submit();
        Fixtures::advanceTo($b['id'], 'declined');
        $this->assertSame('declined', $this->doc($b['id'])['status']);

        $c = Fixtures::submit();
        Fixtures::advanceTo($c['id'], 'completed', ['Cash Unit']);
        $this->assertSame('completed', $this->doc($c['id'])['status']);
    }
}
