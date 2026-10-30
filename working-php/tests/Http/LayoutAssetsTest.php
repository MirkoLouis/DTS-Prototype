<?php

namespace Tests\Http;

use App\Common\Database\Database;
use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

class LayoutAssetsTest extends TestCase
{
    // The app layout must not reference the removed client-side libsodium
    public function test_app_layout_does_not_load_libsodium(): void
    {
        $client = HttpClient::start();
        $client->loginAs('admin');
        $response = $client->get('/admin-dashboard');

        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString('libsodium', $response['body']);
    }

    // Intake finalization (server-side signing) works without any browser crypto
    public function test_signing_still_works_without_client_crypto(): void
    {
        $submitted = Fixtures::submit();
        $client = HttpClient::start();
        $client->loginAs('records.unit');
        $client->get('/intake');

        // Finalize with a route and the PIN
        $response = $client->post("/documents/{$submitted['id']}/finalize", [
            'final_route' => json_encode(['Cash Unit']),
            'pin' => TestDatabase::PIN,
        ]);
        $this->assertSame(302, $response['status']);
        $this->assertSame('/intake', $response['headers']['location']);

        // The newest log entry is the finalization
        $row = Database::getInstance()->query(
            'SELECT action FROM document_logs WHERE document_id = :id ORDER BY id DESC LIMIT 1',
            [':id' => $submitted['id']]
        )->fetch();
        $this->assertSame('Accepted and Document Routing finalized', $row['action']);
    }
}
