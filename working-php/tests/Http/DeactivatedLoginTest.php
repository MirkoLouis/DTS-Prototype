<?php

namespace Tests\Http;

use App\Modules\Users\UserService;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Deactivated accounts cannot sign in, and signed-in sessions end on their next request.
 */
class DeactivatedLoginTest extends TestCase
{
    // Deactivate the Cash Unit account as the admin
    private function deactivateCash(): void
    {
        (new UserService())->deactivate($this->fx['users']['cash.unit'], $this->fx['users']['admin']);
    }

    // A signed-in Cash Unit client
    private function cashClient(): HttpClient
    {
        $client = HttpClient::start();
        $client->loginAs('cash.unit');

        return $client;
    }

    // The correct password of a deactivated account is refused
    public function test_deactivated_user_login_fails(): void
    {
        $this->deactivateCash();
        $client = HttpClient::start();
        $client->get('/login');

        $response = $client->post('/login', ['email' => 'cash.unit', 'password' => TestDatabase::PASSWORD]);

        $this->assertSame(302, $response['status']);
        $this->assertStringContainsString('/login', $response['headers']['location']);
    }

    // A signed-in user is signed out on the next request after deactivation
    public function test_signed_in_user_is_signed_out_on_next_request(): void
    {
        $client = $this->cashClient();
        $this->deactivateCash();

        $first = $client->get('/tasks');
        $this->assertSame(302, $first['status']);
        $this->assertStringContainsString('/login', $first['headers']['location']);
        $this->assertStringContainsString('Your account is no longer active.', $client->get('/login')['body']);

        // The session is no longer authenticated
        $second = $client->get('/tasks');
        $this->assertSame(302, $second['status']);
        $this->assertStringContainsString('/login', $second['headers']['location']);
    }

    // A page the user had cached is not served after deactivation
    public function test_deactivated_user_cannot_read_cached_page(): void
    {
        $client = $this->cashClient();
        $this->assertSame(200, $client->get('/statistics')['status']);
        $this->deactivateCash();

        $response = $client->get('/statistics');

        $this->assertSame(302, $response['status']);
        $this->assertStringContainsString('/login', $response['headers']['location']);
    }

    // Background polling gets a 401 JSON answer instead of an HTML redirect
    public function test_deactivated_user_api_poll_gets_401(): void
    {
        $client = $this->cashClient();
        $this->deactivateCash();

        $response = $client->get('/api/notifications/unread');

        $this->assertSame(401, $response['status']);
        $this->assertFalse(json_decode($response['body'], true)['success']);
    }

    // Reactivation restores sign-in
    public function test_reactivated_user_can_log_in(): void
    {
        $this->deactivateCash();
        (new UserService())->reactivate($this->fx['users']['cash.unit']);

        $client = HttpClient::start();
        $client->loginAs('cash.unit');

        $this->assertSame(200, $client->get('/tasks')['status']);
    }
}
