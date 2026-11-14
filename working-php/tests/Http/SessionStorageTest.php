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

    // The session file of the most recently used session
    private function newestSessionFile(): string
    {
        $files = glob(SessionService::directory() . '/sess_*') ?: [];
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    // The last-activity time stored in a session file
    private function lastActivity(string $file): int
    {
        preg_match('/last_activity\|i:(\d+);/', file_get_contents($file), $match);

        return (int) ($match[1] ?? 0);
    }

    // Make a session look as if it was last used the given number of seconds ago
    private function age(string $file, int $seconds): void
    {
        $contents = preg_replace('/last_activity\|i:\d+;/', 'last_activity|i:' . (time() - $seconds) . ';', file_get_contents($file));
        file_put_contents($file, $contents);
    }

    // A session left idle for longer than the limit is ended on its next request, not whenever cleanup happens to run
    public function test_session_idle_past_the_limit_is_ended_on_the_next_request(): void
    {
        $client = HttpClient::start();
        $client->loginAs('cash.unit');
        $this->assertSame(200, $client->get('/tasks')['status']);

        $this->age($this->newestSessionFile(), 43201);
        $response = $client->get('/tasks');

        $this->assertSame(302, $response['status']);
        $this->assertStringContainsString('/login', $response['headers']['location'] ?? '');
        $this->assertStringContainsString('session has expired', $client->get('/login')['body']);
    }

    // A session used within the limit keeps working
    public function test_session_idle_within_the_limit_keeps_working(): void
    {
        $client = HttpClient::start();
        $client->loginAs('cash.unit');
        $client->get('/tasks');

        $this->age($this->newestSessionFile(), 43000);

        $this->assertSame(200, $client->get('/tasks')['status']);
    }

    // Background polling does not count as activity, so an open but unattended tab still expires
    public function test_background_polling_does_not_extend_the_session(): void
    {
        $client = HttpClient::start();
        $client->loginAs('cash.unit');
        $client->get('/tasks');
        $file = $this->newestSessionFile();
        $this->age($file, 1000);
        $before = $this->lastActivity($file);

        $this->assertSame(200, $client->get('/api/notifications/unread')['status']);
        $this->assertSame($before, $this->lastActivity($file));

        // A page the user opens does count
        $client->get('/tasks');
        $this->assertGreaterThan($before, $this->lastActivity($file));
    }
}
