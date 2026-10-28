<?php

namespace Tests\Http;

use Tests\Support\HttpClient;
use Tests\TestCase;

class DebugLoggingTest extends TestCase
{
    // The router debug file must not grow when a request is served
    public function test_requests_do_not_write_tmp_router_log(): void
    {
        // Remember the file size (or absence) before the request
        $before = file_exists('/tmp/router.log') ? filesize('/tmp/router.log') : -1;

        HttpClient::start()->get('/login');

        // The size (or absence) must be unchanged afterwards
        clearstatcache();
        $after = file_exists('/tmp/router.log') ? filesize('/tmp/router.log') : -1;
        $this->assertSame($before, $after);
    }

    // The navigation log keeps recording requests
    public function test_navigation_log_still_records_request(): void
    {
        HttpClient::start()->get('/login');

        // Read the last line of the navigation log
        $lines = file(BASE_PATH . '/storage/logs/navigation.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $last = end($lines);
        $this->assertMatchesRegularExpression('/ms$/', $last);
        $this->assertStringContainsString(' to /login ', $last);
    }
}
