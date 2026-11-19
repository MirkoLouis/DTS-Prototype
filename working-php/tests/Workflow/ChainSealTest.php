<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Common\Security\IntegrityKey;
use App\Modules\Documents\DocumentWorkflowService;
use App\Modules\Documents\Jobs\CleanupStalePendingDocumentsJob;
use App\Modules\Integrity\ChainSealService;
use App\Modules\Integrity\PreActionIntegrityService;
use App\Modules\System\SystemAuditService;
use App\Modules\Users\UserModel;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Entry seals and chain seals: what they cover, that every workflow action keeps them valid, and how the
 * automatic freeze, unfreeze and auto-resolve treat them.
 */
#[Group('thesis-integrity')]
class ChainSealTest extends TestCase
{
    // Leave no key override behind for other tests
    protected function tearDown(): void
    {
        putenv(IntegrityKey::TEST_OVERRIDE_VARIABLE);
        parent::tearDown();
    }

    // Fetch a document row
    private function doc(int $id): array
    {
        return Database::getInstance()->query('SELECT * FROM documents WHERE id = :id', [':id' => $id])->fetch();
    }

    // Fetch a document's log rows in order
    private function logs(int $id): array
    {
        return Database::getInstance()->query('SELECT * FROM document_logs WHERE document_id = :id ORDER BY id ASC', [':id' => $id])->fetchAll();
    }

    // Load the admin user
    private function admin(): UserModel
    {
        return UserModel::findById($this->fx['users']['admin']);
    }

    // Run the automatic freeze the way the policy does: inside a transaction (log entries refuse to be written outside one)
    private function freezeInTransaction(array $document): void
    {
        $connection = Database::getInstance()->getConnection();
        $connection->beginTransaction();
        PreActionIntegrityService::freezeTampered($document, 'Pre-Action Verification');
        $connection->commit();
    }

    // Assert every entry seal of the document and its chain seal verify, and the pre-action check passes
    private function assertSealed(int $id, string $step): void
    {
        $logs = $this->logs($id);
        $this->assertNotEmpty($logs, $step);
        foreach ($logs as $log) {
            $this->assertTrue(ChainSealService::entrySealIsValid($log), "{$step}: entry {$log['id']} seal");
        }
        $this->assertTrue(ChainSealService::documentSealIsValid($this->doc($id)), "{$step}: chain seal");
        $this->assertTrue(PreActionIntegrityService::passes($this->doc($id)), "{$step}: pre-action check");
    }

    // The chain seal is exactly this list, normalized once: ids as integers, NULL kept, counts as integers
    public function test_chain_seal_input_is_normalized_once(): void
    {
        // Database rows may deliver numbers as text; the sealed list does not change
        $asText = ['id' => '42', 'status' => 'completed', 'current_step' => '2', 'current_department_id' => '5', 'decline_reason' => null, 'released_by_user_id' => '7', 'released_at' => '2026-11-18 09:30:00'];
        $asNumbers = ['id' => 42, 'status' => 'completed', 'current_step' => 2, 'current_department_id' => 5, 'decline_reason' => null, 'released_by_user_id' => 7, 'released_at' => '2026-11-18 09:30:00'];
        $newest = ['id' => '99', 'hash' => 'abc'];

        $expected = ['chain-seal-v2', 42, 'completed', 2, 5, null, 7, '2026-11-18 09:30:00', 99, 'abc', 4];
        $this->assertSame($expected, ChainSealService::chainSealInput($asText, $newest, 4));
        $this->assertSame($expected, ChainSealService::chainSealInput($asNumbers, $newest, 4));

        // NULL and an empty decline reason differ; a document without logs has null head values and a zero count; no release date is null
        $this->assertSame(['chain-seal-v2', 42, 'completed', null, null, '', null, null, null, null, 0], ChainSealService::chainSealInput(['id' => 42, 'status' => 'completed', 'decline_reason' => ''], null, 0));

        // The v1 input (kept only for the upgrade) has no marker and no release date
        $this->assertSame([42, 'completed', 2, 5, null, 7, 99, 'abc', 4], ChainSealService::chainSealInputV1($asText, $newest, 4));
    }

    // Golden value: HMAC-SHA256 over the canonical JSON, with a fixed key and independent computation
    public function test_chain_seal_is_hmac_sha256_of_the_canonical_json(): void
    {
        $key = str_repeat('K', 32);
        putenv(IntegrityKey::TEST_OVERRIDE_VARIABLE . '=' . base64_encode($key));
        $document = ['id' => 42, 'status' => 'completed', 'current_step' => 2, 'current_department_id' => 5, 'decline_reason' => 'ñ / "q"', 'released_by_user_id' => 7, 'released_at' => '2026-11-18 09:30:00'];

        // v2 starts with the version marker and covers the release date; v1 is the old input without both
        $expectedJson = '["chain-seal-v2",42,"completed",2,5,"ñ / \"q\"",7,"2026-11-18 09:30:00",99,"abc",4]';
        $this->assertSame(hash_hmac('sha256', $expectedJson, $key), ChainSealService::chainSeal($document, ['id' => 99, 'hash' => 'abc'], 4));
        $this->assertSame(hash_hmac('sha256', '[42,"completed",2,5,"ñ / \"q\"",7,99,"abc",4]', $key), ChainSealService::chainSealV1($document, ['id' => 99, 'hash' => 'abc'], 4));

        // The entry seal binds the block hash and the SHA-256 of the stored snapshot text (null for a missing snapshot)
        $snapshot = '{"title": "ñ"}';
        $this->assertSame(hash_hmac('sha256', '["abc","' . hash('sha256', $snapshot) . '"]', $key), ChainSealService::entrySeal('abc', $snapshot));
        $this->assertSame(hash_hmac('sha256', '["abc",null]', $key), ChainSealService::entrySeal('abc', null));
        $this->assertNotSame(ChainSealService::entrySeal('abc', $snapshot), ChainSealService::entrySeal('abc', $snapshot . ' '));
    }

    // Every action that writes a log entry leaves valid entry seals and a matching chain seal
    public function test_every_workflow_action_leaves_valid_seals(): void
    {
        $service = new DocumentWorkflowService();
        $pin = TestDatabase::PIN;
        $records = Fixtures::user('records.unit');
        $cash = Fixtures::user('cash.unit');
        $admin = $this->admin();

        // Submit
        $doc = Fixtures::submit(['title' => 'Sealed Journey']);
        $id = $doc['id'];
        $code = $doc['tracking_code'];
        $this->assertSealed($id, 'submit');

        // Intake (finalize the route), receive, complete, receive for release, release
        $service->finalizeIntake($id, ['Cash Unit'], $records, $pin);
        $this->assertSealed($id, 'finalize intake');
        $service->scanDocument($code, $cash, $pin);
        $this->assertSealed($id, 'receive');
        $service->completeTask($id, '', $cash, $pin);
        $this->assertSealed($id, 'complete');
        $service->scanDocument($code, $records, $pin);
        $this->assertSealed($id, 'ready for release');
        $service->releaseDocument($id, $records, $pin);
        $this->assertSealed($id, 'release');

        // Freeze and unfreeze by the admin
        $service->freezeDocument($code, $admin, $pin);
        $this->assertSealed($id, 'admin freeze');
        $service->unfreezeDocument($code, $admin, $pin);
        $this->assertSealed($id, 'unfreeze');

        // Auto-resolve after a title edit
        Database::getInstance()->query("UPDATE documents SET title = 'Edited' WHERE id = :id", [':id' => $id]);
        $service->autoResolveDocument($code, $admin, $pin);
        $this->assertSealed($id, 'auto-resolve');
        $this->assertSame('Sealed Journey', $this->doc($id)['title']);

        // Decline at intake
        $declined = Fixtures::submit();
        $service->declineDocument($declined['id'], 'Incomplete requirements.', $records, $pin);
        $this->assertSealed($declined['id'], 'decline');

        // Decline by the stale-document job (a system entry)
        $stale = Fixtures::submit();
        (new CleanupStalePendingDocumentsJob())->handle(0);
        $this->assertSame('declined', $this->doc($stale['id'])['status']);
        $this->assertSealed($stale['id'], 'stale cleanup');
    }

    // Writing the seal changes neither the version nor the block hash inputs: the chain still audits at 100 %
    public function test_sealing_does_not_touch_version_or_block_hashes(): void
    {
        $doc = Fixtures::submit();
        $before = $this->doc($doc['id']);
        ChainSealService::refresh($doc['id']);
        $after = $this->doc($doc['id']);

        $this->assertSame($before['version'], $after['version']);
        $this->assertSame($before['updated_at'], $after['updated_at']);
        $this->assertSame($before['chain_seal'], $after['chain_seal']);
    }

    // The automatic freeze writes a sealed entry but keeps the chain-seal mismatch, so the document stays flagged
    public function test_automatic_freeze_keeps_the_chain_seal_mismatch(): void
    {
        $doc = Fixtures::submit();
        Fixtures::advanceTo($doc['id'], 'processing', ['Cash Unit']);
        Database::getInstance()->query("UPDATE documents SET status = 'in_transit' WHERE id = :id", [':id' => $doc['id']]);
        $sealBefore = $this->doc($doc['id'])['chain_seal'];

        // The pre-action check fails and freezes the document
        $this->assertFalse(PreActionIntegrityService::passes($this->doc($doc['id'])));
        $this->freezeInTransaction($this->doc($doc['id']));

        // The freeze entry is sealed, the chain seal is untouched, and the document still fails the check
        $frozen = $this->doc($doc['id']);
        $logs = $this->logs($doc['id']);
        $newest = end($logs);
        $this->assertSame('frozen', $frozen['status']);
        $this->assertSame('System Auto-Freeze', $newest['action']);
        $this->assertTrue(ChainSealService::entrySealIsValid($newest));
        $this->assertSame($sealBefore, $frozen['chain_seal']);
        $this->assertFalse(ChainSealService::documentSealIsValid($frozen));
        $this->assertFalse(PreActionIntegrityService::passes($frozen));
    }

    // Unfreeze re-seals: the document passes again, with the status and step restored from the verified entry
    public function test_unfreeze_after_automatic_freeze_restores_and_reseals(): void
    {
        $doc = Fixtures::submit();
        Fixtures::advanceTo($doc['id'], 'in_transit', ['Cash Unit']);
        $original = $this->doc($doc['id']);
        Database::getInstance()->query("UPDATE documents SET status = 'processing', current_step = 2 WHERE id = :id", [':id' => $doc['id']]);
        $this->freezeInTransaction($this->doc($doc['id']));

        $status = (new DocumentWorkflowService())->unfreezeDocument($doc['tracking_code'], $this->admin(), TestDatabase::PIN);

        $restored = $this->doc($doc['id']);
        $this->assertSame('in_transit', $status);
        $this->assertSame($original['current_step'], $restored['current_step']);
        $this->assertSame($original['current_department_id'], $restored['current_department_id']);
        $this->assertSealed($doc['id'], 'unfreeze');
    }

    // Auto-resolve re-seals and also restores the step, holder, decline reason and released-by user
    public function test_autoresolve_restores_sealed_fields_and_reseals(): void
    {
        $doc = Fixtures::submit(['title' => 'Original Title']);
        Fixtures::advanceTo($doc['id'], 'completed', ['Cash Unit']);
        $original = $this->doc($doc['id']);
        Database::getInstance()->query("UPDATE documents SET title = 'Tampered', status = 'processing', current_step = 1, current_department_id = :d, decline_reason = 'x', released_by_user_id = NULL WHERE id = :id", [
            ':d' => $this->fx['departments']['Legal Unit'],
            ':id' => $doc['id'],
        ]);

        (new DocumentWorkflowService())->autoResolveDocument($doc['tracking_code'], $this->admin(), TestDatabase::PIN);

        $restored = $this->doc($doc['id']);
        foreach (['title', 'status', 'current_step', 'current_department_id', 'decline_reason', 'released_by_user_id'] as $field) {
            $this->assertSame($original[$field], $restored[$field], $field);
        }
        $this->assertSealed($doc['id'], 'auto-resolve');
    }

    // An admin freeze of a document that already fails the check does not re-seal the tampered state
    public function test_admin_freeze_does_not_launder_a_tampered_document(): void
    {
        $doc = Fixtures::submit();
        Fixtures::advanceTo($doc['id'], 'completed', ['Cash Unit']);
        Database::getInstance()->query("UPDATE documents SET status = 'processing' WHERE id = :id", [':id' => $doc['id']]);

        (new DocumentWorkflowService())->freezeDocument($doc['tracking_code'], $this->admin(), TestDatabase::PIN);

        $this->assertFalse(ChainSealService::documentSealIsValid($this->doc($doc['id'])));
    }

    // The stale-document job freezes a tampered pending document instead of declining and re-sealing it
    public function test_stale_cleanup_does_not_launder_a_tampered_document(): void
    {
        $doc = Fixtures::submit();
        Database::getInstance()->query("UPDATE documents SET current_step = 4 WHERE id = :id", [':id' => $doc['id']]);

        (new CleanupStalePendingDocumentsJob())->handle(0);

        $row = $this->doc($doc['id']);
        $this->assertSame('frozen', $row['status']);
        $this->assertFalse(ChainSealService::documentSealIsValid($row));
    }

    // Recovery never rolls back: when the newest regular entry's seal is broken it refuses, changes nothing and records the refusal
    public function test_recovery_refuses_when_the_newest_entry_seal_is_broken(): void
    {
        $doc = Fixtures::submit();
        Fixtures::advanceTo($doc['id'], 'ready_for_release', ['Cash Unit']);
        $logs = $this->logs($doc['id']);
        $newest = end($logs);
        $this->assertSame('Ready for Releasing', $newest['action']);
        Database::getInstance()->query('UPDATE document_logs SET seal = :s WHERE id = :id', [':s' => str_repeat('0', 64), ':id' => $newest['id']]);
        Database::getInstance()->query("UPDATE documents SET status = 'processing' WHERE id = :id", [':id' => $doc['id']]);
        @unlink(SystemAuditService::path());

        foreach (['unfreezeDocument', 'autoResolveDocument'] as $method) {
            $rowBefore = $this->doc($doc['id']);
            $logsBefore = $this->logs($doc['id']);
            try {
                (new DocumentWorkflowService())->$method($doc['tracking_code'], $this->admin(), TestDatabase::PIN);
                $this->fail("{$method} must not restore from an older entry.");
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                throw $e;
            } catch (\Exception $e) {
                $this->assertStringContainsString('cannot be trusted for recovery', $e->getMessage());
                $this->assertStringContainsString('restoring a backup', $e->getMessage());
            }
            $this->assertEquals($rowBefore, $this->doc($doc['id']), $method);
            $this->assertEquals($logsBefore, $this->logs($doc['id']), $method);
        }

        // Each refusal is in the system audit log with the document and the failing entry
        $lines = array_map(fn ($l) => json_decode($l, true), file(SystemAuditService::path(), FILE_IGNORE_NEW_LINES));
        $this->assertCount(2, $lines);
        $this->assertSame('recovery_refused', $lines[0]['event']);
        $this->assertSame($doc['tracking_code'], $lines[0]['data']['tracking_code']);
        $this->assertSame((int) $newest['id'], (int) $lines[0]['data']['entry_id']);
    }

    // With the entry seals gone, unfreeze and auto-resolve refuse and change nothing
    public function test_recovery_refuses_when_no_entry_verifies(): void
    {
        $doc = Fixtures::submit(['title' => 'Original Title']);
        Fixtures::advanceTo($doc['id'], 'completed', ['Cash Unit']);
        Database::getInstance()->query("UPDATE document_logs SET seal = NULL WHERE document_id = :id", [':id' => $doc['id']]);
        Database::getInstance()->query("UPDATE documents SET title = 'Tampered' WHERE id = :id", [':id' => $doc['id']]);
        $service = new DocumentWorkflowService();

        foreach (['unfreezeDocument', 'autoResolveDocument'] as $method) {
            $rowBefore = $this->doc($doc['id']);
            $logsBefore = $this->logs($doc['id']);
            try {
                $service->$method($doc['tracking_code'], $this->admin(), TestDatabase::PIN);
                $this->fail("{$method} should refuse when no entry has a valid seal.");
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                throw $e;
            } catch (\Exception $e) {
                $this->assertStringContainsString('cannot be trusted for recovery', $e->getMessage());
                $this->assertStringContainsString('Nothing was changed', $e->getMessage());
            }
            $this->assertEquals($rowBefore, $this->doc($doc['id']), $method);
            $this->assertEquals($logsBefore, $this->logs($doc['id']), $method);
        }
    }

    // System Overview shows the seal counts and lists the failing document as a chain seal mismatch with an Auto-resolve button
    public function test_system_overview_shows_seal_results(): void
    {
        $doc = Fixtures::submit();
        Fixtures::advanceTo($doc['id'], 'completed', ['Cash Unit']);
        Database::getInstance()->query("UPDATE documents SET status = 'processing' WHERE id = :id", [':id' => $doc['id']]);
        \Tests\Support\IntegrityAudit::run($this->fx['users']['admin']);

        $client = \Tests\Support\HttpClient::start();
        $client->loginAs('admin');
        $body = $client->get('/system-overview')['body'];

        $this->assertStringContainsString('Chain seal mismatches', $body);
        $this->assertStringContainsString('Invalid entry seals', $body);
        $this->assertStringContainsString('Chain Seal Mismatch', $body);
        $this->assertStringContainsString($doc['tracking_code'], $body);
        $this->assertStringContainsString('/documents/' . $doc['tracking_code'] . '/autoresolve', $body);
    }

    // A document with no logs has nothing to seal, and its seal check passes only while the seal is empty
    public function test_document_without_logs_is_valid_only_without_a_chain_seal(): void
    {
        $db = Database::getInstance();
        $db->query("INSERT INTO documents (tracking_code, title, purpose_id, status, current_step, created_at, updated_at) VALUES ('NOLOGS-1', 't', :p, 'pending', 0, NOW(), NOW())", [':p' => array_values($this->fx['purposes'])[0]]);
        $row = $db->query("SELECT * FROM documents WHERE tracking_code = 'NOLOGS-1'")->fetch();

        $this->assertTrue(ChainSealService::documentSealIsValid($row));
        $this->assertTrue(PreActionIntegrityService::passes($row));

        $row['chain_seal'] = str_repeat('a', 64);
        $this->assertFalse(ChainSealService::documentSealIsValid($row));
    }

    // A document whose logs were all deleted but that still has a chain seal fails the pre-action check itself
    public function test_pre_action_check_fails_for_a_sealed_document_without_logs(): void
    {
        $doc = Fixtures::submit();
        Database::getInstance()->query('DELETE FROM document_logs WHERE document_id = :id', [':id' => $doc['id']]);
        $row = $this->doc($doc['id']);
        $this->assertNotEmpty($row['chain_seal']);

        $this->assertFalse(PreActionIntegrityService::passes($row));
    }

    // Editing any byte inside the newest entry's snapshot invalidates its entry seal, fails the pre-action check, and recovery refuses
    #[\PHPUnit\Framework\Attributes\DataProvider('snapshotFields')]
    public function test_snapshot_edit_invalidates_the_entry_seal(string $field, string $jsonValue): void
    {
        $doc = Fixtures::submit();
        Fixtures::advanceTo($doc['id'], 'completed', ['Cash Unit']);
        $logs = $this->logs($doc['id']);
        $newest = end($logs);
        $this->assertTrue(ChainSealService::entrySealIsValid($newest));

        Database::getInstance()->query("UPDATE document_logs SET document_snapshot = JSON_SET(document_snapshot, :path, CAST(:v AS JSON)) WHERE id = :id", [':path' => '$.' . $field, ':v' => $jsonValue, ':id' => $newest['id']]);

        $editedLogs = $this->logs($doc['id']);
        $edited = end($editedLogs);
        $this->assertFalse(ChainSealService::entrySealIsValid($edited), $field);
        $this->assertFalse(PreActionIntegrityService::passes($this->doc($doc['id'])), $field);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('cannot be trusted for recovery');
        (new DocumentWorkflowService())->autoResolveDocument($doc['tracking_code'], $this->admin(), TestDatabase::PIN);
    }

    /**
     * field => [field, JSON value written into the snapshot]
     */
    public static function snapshotFields(): array
    {
        return [
            'current_step' => ['current_step', '9'],
            'current_department_id' => ['current_department_id', '1'],
            'decline_reason' => ['decline_reason', '"forged"'],
            'released_by_user_id' => ['released_by_user_id', '1'],
            'an unrelated field' => ['details', '"x"'],
        ];
    }

    // Unfreeze refuses when the content (state-hash fields) changed, and changes and re-seals nothing; Auto-resolve restores it
    public function test_unfreeze_refuses_changed_content_and_autoresolve_restores_it(): void
    {
        $doc = Fixtures::submit(['title' => 'Original Title']);
        Fixtures::advanceTo($doc['id'], 'completed', ['Cash Unit']);
        Database::getInstance()->query("UPDATE documents SET title = 'Tampered' WHERE id = :id", [':id' => $doc['id']]);
        $service = new DocumentWorkflowService();
        $service->freezeDocument($doc['tracking_code'], $this->admin(), TestDatabase::PIN);
        $rowBefore = $this->doc($doc['id']);
        $logsBefore = $this->logs($doc['id']);

        try {
            $service->unfreezeDocument($doc['tracking_code'], $this->admin(), TestDatabase::PIN);
            $this->fail('Unfreeze must refuse a document whose content was changed.');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->assertSame("The document's content was changed. Use Auto-resolve to restore it from the last verified entry.", $e->getMessage());
        }
        $this->assertEquals($rowBefore, $this->doc($doc['id']));
        $this->assertEquals($logsBefore, $this->logs($doc['id']));

        // The refusal is recorded in the system audit log, with the reason
        $audit = json_decode((string) file(SystemAuditService::path(), FILE_IGNORE_NEW_LINES)[0], true);
        $this->assertSame('recovery_refused', $audit['event']);
        $this->assertSame($doc['tracking_code'], $audit['data']['tracking_code']);
        $this->assertStringContainsString('content was changed', $audit['data']['reason']);

        $service->autoResolveDocument($doc['tracking_code'], $this->admin(), TestDatabase::PIN);
        $this->assertSame('Original Title', $this->doc($doc['id'])['title']);
        $this->assertSealed($doc['id'], 'auto-resolve');
    }

    // A document that has logs but no chain seal is refused (not frozen) by the pre-action check, the policy, a workflow action and the cleanup job
    public function test_unsealed_document_is_refused_not_frozen(): void
    {
        $doc = Fixtures::submit();
        Fixtures::advanceTo($doc['id'], 'in_transit', ['Cash Unit']);
        $stale = Fixtures::submit();
        Database::getInstance()->query('UPDATE documents SET chain_seal = NULL');
        $logsBefore = count($this->logs($doc['id']));
        $rowBefore = $this->doc($doc['id']);

        $errorLog = \App\Common\Config\Config::testPath('logs/php-error.log');
        @mkdir(dirname($errorLog), 0770, true);
        @unlink($errorLog);
        ini_set('error_log', $errorLog);

        // The check, the policy and a workflow action each throw the dedicated exception with the operator instruction
        foreach ([
            fn () => PreActionIntegrityService::passes($rowBefore),
            fn () => (new \App\Modules\Documents\DocumentPolicy())->process($this->admin(), $rowBefore),
            fn () => (new DocumentWorkflowService())->scanDocument($doc['tracking_code'], Fixtures::user('cash.unit'), TestDatabase::PIN),
        ] as $call) {
            try {
                $call();
                $this->fail('An unsealed document must be refused.');
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                throw $e;
            } catch (\App\Modules\Integrity\DocumentNotSealedException $e) {
                $this->assertSame('This document has not been sealed yet; an administrator must run the sealing step.', $e->getMessage());
            }
        }

        // The cleanup job skips it as well; nothing is frozen, declined or written
        $this->assertSame(0, (new CleanupStalePendingDocumentsJob())->handle(0));
        $this->assertEquals($rowBefore, $this->doc($doc['id']));
        $this->assertSame('pending', $this->doc($stale['id'])['status']);
        $this->assertSame($logsBefore, count($this->logs($doc['id'])));
        $this->assertStringContainsString('has not been sealed yet', (string) file_get_contents($errorLog));
        ini_restore('error_log');
    }

    // On a database recorded as sealed a missing chain seal is tampering: the check fails (and the document is frozen), the audit counts it, the overview lists it
    public function test_missing_chain_seal_on_a_recorded_sealed_database_is_tampering(): void
    {
        $doc = Fixtures::submit();
        Fixtures::advanceTo($doc['id'], 'in_transit', ['Cash Unit']);
        SystemAuditService::record(ChainSealService::SEALED_EVENT, ['database' => \App\Common\Config\Config::get('database', 'dbname')]);
        Database::getInstance()->query('UPDATE documents SET chain_seal = NULL WHERE id = :id', [':id' => $doc['id']]);

        // The pre-action check fails instead of refusing, and the policy freezes the document like any mismatch
        $row = $this->doc($doc['id']);
        $this->assertFalse(PreActionIntegrityService::passes($row));
        $connection = Database::getInstance()->getConnection();
        $connection->beginTransaction();
        $this->assertFalse((new \App\Modules\Documents\DocumentPolicy())->process($this->admin(), $row));
        $connection->commit();
        $this->assertSame('frozen', $this->doc($doc['id'])['status']);

        // The integrity check counts it as a chain seal mismatch, not as unsealed
        $results = \Tests\Support\IntegrityAudit::run($this->fx['users']['admin']);
        $this->assertTrue($results['database_sealed']);
        $this->assertSame(1, $results['invalid_chain_seals']);
        $this->assertSame([$doc['tracking_code']], $results['invalid_chain_seal_tracking_codes']);
        $this->assertSame(0, $results['unsealed_documents']);

        // System Overview lists it in the issue table
        $client = \Tests\Support\HttpClient::start();
        $client->loginAs('admin');
        $body = $client->get('/system-overview')['body'];
        $this->assertStringContainsString('Chain Seal Mismatch', $body);
        $this->assertStringContainsString($doc['tracking_code'], $body);
    }

    // Recovery of a frozen document that is merely not sealed yet says "seal first": no "restore a backup" and no refusal record
    public function test_recovery_of_a_not_yet_sealed_document_says_to_seal_first(): void
    {
        $doc = Fixtures::submit();
        Database::getInstance()->query('UPDATE documents SET chain_seal = NULL');
        (new DocumentWorkflowService())->freezeDocument($doc['tracking_code'], $this->admin(), TestDatabase::PIN);

        foreach (['unfreezeDocument', 'autoResolveDocument'] as $method) {
            try {
                (new DocumentWorkflowService())->$method($doc['tracking_code'], $this->admin(), TestDatabase::PIN);
                $this->fail("{$method} must ask for the sealing step.");
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                throw $e;
            } catch (\App\Modules\Integrity\DocumentNotSealedException $e) {
                $this->assertStringContainsString('sealing step', $e->getMessage());
                $this->assertStringNotContainsString('backup', $e->getMessage());
            }
        }
        $this->assertFileDoesNotExist(SystemAuditService::path());
    }

    // Deleting every log row of a sealed document does not let intake, decline or release re-seal it from a new genesis (through the HTTP action)
    public function test_deleting_all_logs_does_not_let_a_manage_action_through(): void
    {
        $doc = Fixtures::submit();
        Database::getInstance()->query('DELETE FROM document_logs WHERE document_id = :id', [':id' => $doc['id']]);
        $client = \Tests\Support\HttpClient::start();
        $client->loginAs('records.unit');
        $managePath = "/documents/{$doc['id']}/manage";
        $client->get($managePath);

        $client->post('/documents/decline', ['document_id' => $doc['id'], 'reason' => 'Nope.', 'pin' => TestDatabase::PIN], true, $managePath);

        $row = $this->doc($doc['id']);
        $this->assertNotSame('declined', $row['status']);
        $this->assertSame('frozen', $row['status']);
        $this->assertFalse(ChainSealService::documentSealIsValid($row));
    }

    // The integrity check reports unsealed documents and entries as unsealed, not as tampered (so Freeze All ignores them)
    public function test_integrity_check_reports_unsealed_not_tampered(): void
    {
        $doc = Fixtures::submit();
        Fixtures::advanceTo($doc['id'], 'completed', ['Cash Unit']);
        Database::getInstance()->query('UPDATE document_logs SET seal = NULL');
        Database::getInstance()->query('UPDATE documents SET chain_seal = NULL');

        $results = \Tests\Support\IntegrityAudit::run($this->fx['users']['admin']);

        $this->assertSame(1, $results['unsealed_documents']);
        $this->assertGreaterThan(0, $results['unsealed_entries']);
        $this->assertSame(0, $results['invalid_chain_seals']);
        $this->assertSame([], $results['invalid_chain_seal_tracking_codes']);
        $this->assertSame(0, $results['invalid_entry_seals']);
        $this->assertSame([], $results['invalid_entry_seal_ids']);
    }
}
