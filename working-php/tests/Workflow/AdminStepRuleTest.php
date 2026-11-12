<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Modules\Documents\DocumentWorkflowService;
use App\Modules\Users\UserModel;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Admins are bound by the same department-step rule as officers and staff for receive and complete.
 */
#[Group('thesis-workflow')]
class AdminStepRuleTest extends TestCase
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

    // Load the admin user fresh from the database
    private function admin(): UserModel
    {
        return UserModel::findById($this->fx['users']['admin']);
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

    // An admin cannot receive a document waiting at another department's step
    public function test_admin_cannot_receive_at_another_department_step(): void
    {
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'in_transit', ['Cash Unit']);
        $before = $this->logCount($s['id']);

        $message = $this->errorOf(fn () => (new DocumentWorkflowService())->scanDocument($s['tracking_code'], $this->admin(), TestDatabase::PIN));

        $this->assertStringContainsString('Action Denied', $message);
        $this->assertSame('in_transit', $this->doc($s['id'])['status']);
        $this->assertSame($before, $this->logCount($s['id']));
    }

    // An admin cannot complete a task at another department's step
    public function test_admin_cannot_complete_another_department_step(): void
    {
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'processing', ['Cash Unit']);
        $before = $this->logCount($s['id']);

        $message = $this->errorOf(fn () => (new DocumentWorkflowService())->completeTask($s['id'], '', $this->admin(), TestDatabase::PIN));

        $this->assertStringContainsString('Action Denied', $message);
        $this->assertSame('processing', $this->doc($s['id'])['status']);
        $this->assertSame($before, $this->logCount($s['id']));
    }

    // An admin assigned to a department processes that department's step like its staff
    public function test_admin_in_a_department_processes_its_own_step(): void
    {
        Database::getInstance()->query('UPDATE users SET department_id = :d WHERE id = :id', [
            ':d' => $this->fx['departments']['Cash Unit'],
            ':id' => $this->fx['users']['admin'],
        ]);
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'in_transit', ['Cash Unit']);
        $service = new DocumentWorkflowService();

        $service->scanDocument($s['tracking_code'], $this->admin(), TestDatabase::PIN);
        $this->assertSame('processing', $this->doc($s['id'])['status']);

        $service->completeTask($s['id'], '', $this->admin(), TestDatabase::PIN);
        $this->assertSame('in_transit', $this->doc($s['id'])['status']);
        $this->assertSame(2, (int) $this->doc($s['id'])['current_step']);
    }

    // Freeze and unfreeze stay admin-only actions, independent of the step rule
    public function test_admin_keeps_freeze_and_unfreeze(): void
    {
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'in_transit', ['Cash Unit']);
        $service = new DocumentWorkflowService();

        $service->freezeDocument($s['tracking_code'], $this->admin(), TestDatabase::PIN);
        $this->assertSame('frozen', $this->doc($s['id'])['status']);

        $this->assertSame('in_transit', $service->unfreezeDocument($s['tracking_code'], $this->admin(), TestDatabase::PIN));
        $this->assertSame('in_transit', $this->doc($s['id'])['status']);
    }
}
