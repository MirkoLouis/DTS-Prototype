<?php

namespace Tests\Http;

use Tests\Support\HttpClient;
use Tests\TestCase;

class VerifyHashLabelTest extends TestCase
{
    // The system overview shows "Verify Hash" and no "Debugger"/"Decode" labels
    public function test_system_overview_uses_verify_hash_label(): void
    {
        $client = HttpClient::start();
        $client->loginAs('admin');
        $response = $client->get('/system-overview');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Verify Hash', $response['body']);
        $this->assertStringNotContainsString('Debugger', $response['body']);
        $this->assertStringNotContainsString('Decode', $response['body']);
    }
}
