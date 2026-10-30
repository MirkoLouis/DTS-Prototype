<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Integrity\HashChainMicroShardingBuilder;
use App\Integrity\PreActionIntegrityCheck;
use App\Jobs\CleanupStalePendingDocumentsJob;
use App\Models\User;
use App\Services\DocumentWorkflowService;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

class UnfreezeStatusTest extends TestCase
{
    // Fetch the document row by id
    private function doc(int $id): array
    {
        return Database::getInstance()->query('SELECT * FROM documents WHERE id = :id', [':id' => $id])->fetch();
    }

    // Load the admin user
    private function admin(): User
    {
        return User::findById($this->fx['users']['admin']);
    }

    // Count the log rows of a document
    private function logCount(int $id): int
    {
        return (int) Database::getInstance()->query('SELECT COUNT(*) c FROM document_logs WHERE document_id = :id', [':id' => $id])->fetch()['c'];
    }

    // Freeze then unfreeze once, returning the restored status
    private function cycle(string $code): string
    {
        $service = new DocumentWorkflowService();
        $service->freezeDocument($code, $this->admin(), TestDatabase::PIN);
        return $service->unfreezeDocument($code, $this->admin(), TestDatabase::PIN);
    }

    // A declined document must come back as declined
    public function test_unfreeze_declined_document_restores_declined(): void
    {
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'declined');

        $this->assertSame('declined', $this->cycle($s['tracking_code']));
        $this->assertSame('declined', $this->doc($s['id'])['status']);
    }

    // A second freeze/unfreeze cycle must not degrade the status
    public function test_unfreeze_after_second_freeze_restores_original_status(): void
    {
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'completed');

        $this->assertSame('completed', $this->cycle($s['tracking_code']));
        $this->assertSame('completed', $this->cycle($s['tracking_code']));
        $this->assertSame('completed', $this->doc($s['id'])['status']);
    }

    // Auto-resolve of a declined document restores status and tampered title
    public function test_autoresolve_declined_document_restores_declined(): void
    {
        $s = Fixtures::submit(['title' => 'Original Title']);
        Fixtures::advanceTo($s['id'], 'declined');
        Database::getInstance()->query('UPDATE documents SET title = :t WHERE id = :id', [':t' => 'Tampered', ':id' => $s['id']]);

        $status = (new DocumentWorkflowService())->autoResolveDocument($s['tracking_code'], $this->admin(), TestDatabase::PIN);

        $this->assertSame('declined', $status);
        $this->assertSame('Original Title', $this->doc($s['id'])['title']);
    }

    // A pending document must come back as pending
    public function test_unfreeze_pending_restores_pending(): void
    {
        $s = Fixtures::submit();

        $this->assertSame('pending', $this->cycle($s['tracking_code']));
        $this->assertSame('pending', $this->doc($s['id'])['status']);
    }

    // A document declined by the timeout job must come back as declined
    public function test_unfreeze_timeout_declined_document_restores_declined(): void
    {
        $s = Fixtures::submit();
        (new CleanupStalePendingDocumentsJob())->handle(0);
        $this->assertSame('declined', $this->doc($s['id'])['status']);

        $this->assertSame('declined', $this->cycle($s['tracking_code']));
        $this->assertSame('declined', $this->doc($s['id'])['status']);
    }

    // A snapshot that does not match its recorded hash must not be restored
    public function test_autoresolve_refuses_tampered_snapshot(): void
    {
        $db = Database::getInstance();
        $s = Fixtures::submit(['title' => 'Original Title']);
        Fixtures::advanceTo($s['id'], 'completed');

        // Tamper the live row and the newest restorable log's snapshot
        $db->query('UPDATE documents SET title = :t WHERE id = :id', [':t' => 'Tampered', ':id' => $s['id']]);
        $log = $db->query("SELECT id, document_snapshot FROM document_logs WHERE document_id = :id AND action NOT LIKE 'ADMIN:%' AND action <> 'System Auto-Freeze' AND document_snapshot IS NOT NULL ORDER BY id DESC LIMIT 1", [':id' => $s['id']])->fetch();
        $snap = json_decode($log['document_snapshot'], true);
        $snap['title'] = 'ATTACKER';
        $db->query('UPDATE document_logs SET document_snapshot = :s WHERE id = :id', [':s' => json_encode($snap), ':id' => $log['id']]);

        $before = $this->doc($s['id']);
        $logsBefore = $this->logCount($s['id']);

        // Expect refusal with nothing written
        try {
            (new DocumentWorkflowService())->autoResolveDocument($s['tracking_code'], $this->admin(), TestDatabase::PIN);
            $this->fail('Auto-resolve should have refused the tampered snapshot.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('cannot be trusted for recovery', $e->getMessage());
        }
        $this->assertEquals($before, $this->doc($s['id']));
        $this->assertSame('Tampered', $this->doc($s['id'])['title']);
        $this->assertSame($logsBefore, $this->logCount($s['id']));
    }

    // A genuine snapshot restores the document and the chain still verifies
    public function test_autoresolve_restores_from_verified_snapshot(): void
    {
        $s = Fixtures::submit(['title' => 'Original Title']);
        Fixtures::advanceTo($s['id'], 'completed');
        Database::getInstance()->query('UPDATE documents SET title = :t WHERE id = :id', [':t' => 'Tampered', ':id' => $s['id']]);

        $status = (new DocumentWorkflowService())->autoResolveDocument($s['tracking_code'], $this->admin(), TestDatabase::PIN);

        $this->assertSame('completed', $status);
        $this->assertSame('Original Title', $this->doc($s['id'])['title']);
        $this->assertTrue(PreActionIntegrityCheck::passes($this->doc($s['id'])));
    }

    // Fetch the newest restorable log row (same filter as the service)
    private function restorableLog(int $id): array
    {
        return Database::getInstance()->query("SELECT * FROM document_logs WHERE document_id = :id AND action NOT LIKE 'ADMIN:%' AND action <> 'System Auto-Freeze' AND document_snapshot IS NOT NULL ORDER BY id DESC LIMIT 1", [':id' => $id])->fetch();
    }

    // Run auto-resolve and assert it refuses without writing anything
    private function assertAutoResolveRefused(int $id, string $code): void
    {
        $before = $this->doc($id);
        $logsBefore = $this->logCount($id);

        // Expect the untrusted-snapshot refusal
        try {
            (new DocumentWorkflowService())->autoResolveDocument($code, $this->admin(), TestDatabase::PIN);
            $this->fail('Auto-resolve should have refused the untrusted snapshot.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->assertSame('The stored snapshot does not match its recorded hash and cannot be trusted for recovery.', $e->getMessage());
        }

        // Nothing may have been written
        $this->assertEquals($before, $this->doc($id));
        $this->assertSame($logsBefore, $this->logCount($id));
    }

    // Editing the snapshot and its state hash consistently must still be refused (chain hash and signature break)
    public function test_autoresolve_refuses_snapshot_with_forged_state_hash(): void
    {
        $db = Database::getInstance();
        $s = Fixtures::submit(['title' => 'Original Title']);
        Fixtures::advanceTo($s['id'], 'completed');

        // Tamper the live row, then forge both snapshot and state hash on the restorable log
        $db->query('UPDATE documents SET title = :t WHERE id = :id', [':t' => 'Tampered', ':id' => $s['id']]);
        $log = $this->restorableLog($s['id']);
        $snap = json_decode($log['document_snapshot'], true);
        $snap['title'] = 'ATTACKER';
        $db->query('UPDATE document_logs SET document_snapshot = :s, document_state_hash = :h WHERE id = :id', [
            ':s' => json_encode($snap),
            ':h' => HashChainMicroShardingBuilder::calculateStateHash($snap),
            ':id' => $log['id']
        ]);

        $this->assertAutoResolveRefused($s['id'], $s['tracking_code']);
        $this->assertSame('Tampered', $this->doc($s['id'])['title']);
    }

    // A snapshot and hash copied from another document must be refused
    public function test_autoresolve_refuses_snapshot_copied_from_another_document(): void
    {
        $db = Database::getInstance();
        $victim = Fixtures::submit(['title' => 'Victim Title']);
        Fixtures::advanceTo($victim['id'], 'completed');
        $donor = Fixtures::submit(['title' => 'Donor Title']);
        Fixtures::advanceTo($donor['id'], 'completed');

        // Tamper the victim row, then copy the donor's snapshot and state hash onto the victim's restorable log
        $db->query('UPDATE documents SET title = :t WHERE id = :id', [':t' => 'Tampered', ':id' => $victim['id']]);
        $donorLog = $this->restorableLog($donor['id']);
        $victimLog = $this->restorableLog($victim['id']);
        $db->query('UPDATE document_logs SET document_snapshot = :s, document_state_hash = :h WHERE id = :id', [
            ':s' => $donorLog['document_snapshot'],
            ':h' => $donorLog['document_state_hash'],
            ':id' => $victimLog['id']
        ]);

        $this->assertAutoResolveRefused($victim['id'], $victim['tracking_code']);
        $this->assertSame('Tampered', $this->doc($victim['id'])['title']);
    }
}
