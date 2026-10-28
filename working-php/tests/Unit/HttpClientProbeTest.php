<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\HttpClient;

class HttpClientProbeTest extends TestCase
{
    // A server without the probe route (production 404) must be refused
    public function test_refuses_server_without_probe(): void
    {
        $this->expectException(RuntimeException::class);
        HttpClient::assertProbeSafe(404, '404 Not Found');
    }

    // A probe reporting the operational database must be refused
    public function test_refuses_operational_database(): void
    {
        $this->expectException(RuntimeException::class);
        HttpClient::assertProbeSafe(200, json_encode(['env' => 'testing', 'db' => 'deped_dts']));
    }

    // A probe reporting a non-testing environment must be refused
    public function test_refuses_non_testing_env(): void
    {
        $this->expectException(RuntimeException::class);
        HttpClient::assertProbeSafe(200, json_encode(['env' => 'production', 'db' => 'deped_dts_test']));
    }

    // A malformed probe body must be refused
    public function test_refuses_malformed_body(): void
    {
        $this->expectException(RuntimeException::class);
        HttpClient::assertProbeSafe(200, 'not json');
    }

    // The testing env with a _test database is accepted
    public function test_accepts_testing_server(): void
    {
        HttpClient::assertProbeSafe(200, json_encode(['env' => 'testing', 'db' => 'deped_dts_test']));
        $this->addToAssertionCount(1);
    }
}
