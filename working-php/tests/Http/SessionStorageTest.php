<?php

namespace Tests\Http;

use App\Common\Config\Config;
use App\Common\Http\SessionService;
use Tests\Support\HttpClient;
use Tests\TestCase;

/**
 * Sessions are stored in the app's own folder and cleaned up by the app, with a 12-hour idle limit.
 */
class SessionStorageTest extends TestCase
{
    // The session settings the web server is running with (test-only probe)
    private function serverSession(): array
    {
        $response = HttpClient::start()->get('/__test/env');

        return json_decode($response['body'], true)['session'];
    }

    // Under the test suite, sessions live in the .test folder
    public function test_session_folder_is_inside_dot_test_when_testing(): void
    {
        $this->assertSame(Config::testPath('sessions'), SessionService::directory());
    }

    // The web server keeps its session files in the app's folder, not the system one
    public function test_session_files_are_stored_in_the_app_folder(): void
    {
        $client = HttpClient::start();
        $client->loginAs('admin');

        $this->assertSame(SessionService::directory(), $this->serverSession()['save_path']);
        $this->assertNotSame([], glob(SessionService::directory() . '/sess_*') ?: []);
    }

    // A session may stay idle for twelve hours by default, and the app removes expired ones itself
    public function test_idle_limit_is_twelve_hours_and_cleanup_is_on(): void
    {
        $this->assertSame(43200, (int) Config::get('app', 'session_lifetime'));

        $session = $this->serverSession();
        $this->assertSame(43200, (int) $session['gc_maxlifetime']);
        $this->assertGreaterThan(0, (int) $session['gc_probability']);
    }

    // Signing in still works, and the session cookie stays hardened
    public function test_session_cookie_is_http_only_and_same_site_strict(): void
    {
        $response = HttpClient::start()->get('/login');
        $cookie = strtolower(implode('; ', (array) ($response['headers']['set-cookie'] ?? [])));

        $this->assertStringContainsString('httponly', $cookie);
        $this->assertStringContainsString('samesite=strict', $cookie);
    }
}
