<?php

namespace Tests\Http;

use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

class DocumentToastCodeTest extends TestCase
{
    // The helper prefixes a message with the document's code once, and leaves messages that already name it
    public function test_document_message_prefixes_code_once(): void
    {
        // Prefix added to a message without the code
        $this->assertSame(
            'Document DEPED-0000000001: This document is not for your department.',
            document_message('DEPED-0000000001', 'This document is not for your department.')
        );

        // A message that already names the document is returned unchanged
        $this->assertSame(
            'Document DEPED-0000000001 not found.',
            document_message('DEPED-0000000001', 'Document DEPED-0000000001 not found.')
        );
    }

    // An admin freeze confirms which document was frozen
    public function test_freeze_toast_names_document(): void
    {
        // An admin on the document's page
        $s = Fixtures::submit();
        $client = HttpClient::start();
        $client->loginAs('admin');
        $page = "/documents/{$s['tracking_code']}";
        $client->get($page);

        // Freeze it from that page
        $response = $client->post("/documents/{$s['tracking_code']}/freeze", ['pin' => TestDatabase::PIN], true, $page);
        $this->assertSame(302, $response['status']);

        // The toast names the document
        $body = $client->get($page)['body'];
        $this->assertStringContainsString("Document {$s['tracking_code']} has been frozen.", $body);
    }

    // A workflow error on scan names the document it was about
    public function test_scan_error_names_document(): void
    {
        // A document already being processed by the Cash Unit, scanned again by the Cash Unit
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'processing', ['Cash Unit']);
        $client = HttpClient::start();
        $client->loginAs('cash.unit');
        $client->get('/tasks');

        // Scan it from the tasks page
        $response = $client->post('/documents/scan', ['tracking_code' => $s['tracking_code'], 'pin' => TestDatabase::PIN], true, '/tasks');
        $this->assertSame(302, $response['status']);

        // The error toast is prefixed with the tracking code
        $body = $client->get('/tasks')['body'];
        $this->assertStringContainsString("Document {$s['tracking_code']}: You are already processing this document.", $body);
    }

    // The intake lookup notice names the document that is already in process
    public function test_intake_lookup_notice_names_document(): void
    {
        // A document already in transit, looked up again at intake
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'in_transit', ['Cash Unit']);
        $client = HttpClient::start();
        $client->loginAs('records.unit');
        $client->get('/intake');
        $client->post('/intake/find', ['tracking_code' => $s['tracking_code']], true, '/intake');

        // The info toast names the document
        $body = $client->get('/intake')['body'];
        $this->assertStringContainsString("Document {$s['tracking_code']} is already in process and cannot be intaked again.", $body);
    }
}
