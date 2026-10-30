<?php

namespace Tests\Http;

use App\Common\Database\Database;
use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

class DeclineRedirectTest extends TestCase
{
    // After a successful decline the officer lands on their dashboard with a toast naming the document
    public function test_successful_decline_redirects_to_dashboard_with_toast(): void
    {
        // A pending document opened on the officer's manage page
        $s = Fixtures::submit();
        $client = HttpClient::start();
        $client->loginAs('records.unit');
        $managePath = "/documents/{$s['id']}/manage";
        $client->get($managePath);

        // Decline it from that page, as the decline modal does
        $response = $client->post('/documents/decline', [
            'document_id' => $s['id'],
            'reason' => 'Incomplete requirements.',
            'pin' => TestDatabase::PIN,
        ], true, $managePath);

        // Redirected to the role dashboard, not back to the manage page
        $this->assertSame(302, $response['status']);
        $this->assertSame('/dashboard', $response['headers']['location'] ?? '');

        // The dashboard (officer → /intake) shows the toast with the tracking code
        $dashboard = $client->get('/dashboard');
        $this->assertSame('/intake', $dashboard['headers']['location'] ?? '');
        $page = $client->get('/intake')['body'];
        $this->assertStringContainsString("Document {$s['tracking_code']} successfully declined.", $page);
        $this->assertStringNotContainsString('Management Unauthorized', $page);

        // The document really was declined
        $status = Database::getInstance()->query('SELECT status FROM documents WHERE id = :id', [':id' => $s['id']])->fetch()['status'];
        $this->assertSame('declined', $status);
    }

    // A failed decline (missing reason) keeps the officer on the page they came from
    public function test_failed_decline_returns_to_referring_page(): void
    {
        // A pending document opened on the officer's manage page
        $s = Fixtures::submit();
        $client = HttpClient::start();
        $client->loginAs('records.unit');
        $managePath = "/documents/{$s['id']}/manage";
        $client->get($managePath);

        // Decline without a reason
        $response = $client->post('/documents/decline', [
            'document_id' => $s['id'],
            'reason' => '',
            'pin' => TestDatabase::PIN,
        ], true, $managePath);

        // Sent back to the manage page, and the document is still pending
        $this->assertSame(302, $response['status']);
        $this->assertStringEndsWith($managePath, $response['headers']['location'] ?? '');
        $status = Database::getInstance()->query('SELECT status FROM documents WHERE id = :id', [':id' => $s['id']])->fetch()['status'];
        $this->assertSame('pending', $status);
    }
}
