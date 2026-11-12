<?php

namespace Tests\Http;

use Tests\Support\HttpClient;
use Tests\TestCase;

class TestEnvProbeTest extends TestCase
{
    // The testing server exposes the probe with its env and database name
    public function test_probe_reports_testing_env_and_test_database(): void
    {
        $response = HttpClient::start()->get('/__test/env');

        $this->assertSame(200, $response['status']);
        $data = json_decode($response['body'], true);
        $this->assertSame('testing', $data['env']);
        $this->assertStringEndsWith('_test', $data['db']);
    }
}
