<?php

namespace Tests\Http;

use App\Modules\Auth\AttemptThrottleService;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    // Load the form, post credentials, then return the redirect and the error shown on the next page
    private function attempt(HttpClient $client, string $username, string $password): array
    {
        $client->get('/login');
        $response = $client->post('/login', ['email' => $username, 'password' => $password]);
        $location = $response['headers']['location'] ?? '';

        // Only a bounce back to /login carries a flashed error
        $page = str_contains($location, '/login') ? $client->get('/login')['body'] : '';

        return ['location' => $location, 'page' => $page];
    }

    // True when the redirect left the login page (authenticated)
    private function loggedIn(array $result): bool
    {
        return $result['location'] !== '' && !str_contains($result['location'], '/login');
    }

    // The third wrong password in a minute shows the timeout message
    public function test_third_failed_login_shows_timeout_message(): void
    {
        $client = HttpClient::start();

        // The first two failures show the normal credentials error
        for ($i = 0; $i < 2; $i++) {
            $result = $this->attempt($client, 'cash.unit', 'wrong');
            $this->assertStringContainsString('These credentials do not match our records.', $result['page']);
            $this->assertStringNotContainsString(AttemptThrottleService::MESSAGE, $result['page']);
        }

        $result = $this->attempt($client, 'cash.unit', 'wrong');
        $this->assertFalse($this->loggedIn($result));
        $this->assertStringContainsString(AttemptThrottleService::MESSAGE, $result['page']);
    }

    // The right password is refused while the timeout lasts
    public function test_correct_password_is_refused_while_locked(): void
    {
        $client = HttpClient::start();
        for ($i = 0; $i < 3; $i++) {
            $this->attempt($client, 'cash.unit', 'wrong');
        }

        $result = $this->attempt($client, 'cash.unit', TestDatabase::PASSWORD);
        $this->assertFalse($this->loggedIn($result));
        $this->assertStringContainsString(AttemptThrottleService::MESSAGE, $result['page']);
    }

    // A successful login resets the failure count
    public function test_successful_login_clears_failures(): void
    {
        $client = HttpClient::start();

        // Two failures, then a success that must clear them
        $this->attempt($client, 'cash.unit', 'wrong');
        $this->attempt($client, 'cash.unit', 'wrong');
        $success = $this->attempt($client, 'cash.unit', TestDatabase::PASSWORD);
        $this->assertTrue($this->loggedIn($success));

        // Log out through the real form (POST with the page's CSRF token)
        $client->get($success['location']);
        $client->post('/logout', []);

        // Two more failures would lock only if the first two still counted
        $this->attempt($client, 'cash.unit', 'wrong');
        $this->attempt($client, 'cash.unit', 'wrong');
        $this->assertTrue($this->loggedIn($this->attempt($client, 'cash.unit', TestDatabase::PASSWORD)));
    }
}
