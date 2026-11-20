<?php

namespace Tests\Http;

use App\Common\Database\Database;
use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * A refused workflow action shows its reason on the page (not only in the browser console) and changes nothing.
 */
class RefusalMessageTest extends TestCase
{
    // Status and number of log entries of a document
    private function state(int $id): array
    {
        $db = Database::getInstance();
        $status = $db->query('SELECT status FROM documents WHERE id = :id', [':id' => $id])->fetch()['status'];
        $logs = (int) $db->query('SELECT COUNT(*) AS c FROM document_logs WHERE document_id = :id', [':id' => $id])->fetch()['c'];

        return ['status' => $status, 'logs' => $logs];
    }

    // A department that is not the document's current step sees why the scan was refused
    public function test_wrong_department_scan_shows_the_reason_on_the_page(): void
    {
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'in_transit', ['Cash Unit']);
        $before = $this->state($s['id']);

        $staff = HttpClient::start();
        $staff->loginAs('personnel.unit');
        $staff->get('/tasks');
        $staff->post('/documents/scan', ['tracking_code' => $s['tracking_code'], 'pin' => TestDatabase::PIN]);
        $page = $staff->get('/tasks')['body'];

        $this->assertStringContainsString("Document {$s['tracking_code']}: This document is not for your department. It is waiting to be received by Cash Unit.", $page);
        $this->assertStringNotContainsString('dts-console-error', $page);
        $this->assertSame($before, $this->state($s['id']));
    }

    // Completing a step that belongs to another department shows who holds it
    public function test_cross_step_completion_shows_the_reason_on_the_page(): void
    {
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'processing', ['Personnel Unit']);
        $before = $this->state($s['id']);

        $staff = HttpClient::start();
        $staff->loginAs('cash.unit');
        $staff->get('/tasks');
        $staff->post('/tasks/' . $s['id'] . '/complete', ['pin' => TestDatabase::PIN]);
        $page = $staff->get('/tasks')['body'];

        $this->assertStringContainsString("Document {$s['tracking_code']}: This document is being processed by the Personnel Unit.", $page);
        $this->assertSame($before, $this->state($s['id']));
    }

    // A scan that finds the document tampered with freezes it and says so on the page
    public function test_tampered_document_scan_shows_that_it_was_frozen(): void
    {
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'in_transit', ['Cash Unit']);
        Database::getInstance()->query("UPDATE documents SET title = 'Tampered' WHERE id = :id", [':id' => $s['id']]);

        $staff = HttpClient::start();
        $staff->loginAs('cash.unit');
        $staff->get('/tasks');
        $staff->post('/documents/scan', ['tracking_code' => $s['tracking_code'], 'pin' => TestDatabase::PIN]);
        $page = $staff->get('/tasks')['body'];

        $this->assertStringContainsString("Document {$s['tracking_code']}: This document is frozen because its record does not match its hash chain. An administrator must review it.", $page);
        $this->assertSame('frozen', $this->state($s['id'])['status']);
    }
}
