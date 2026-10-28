<?php

namespace Tests\Http;

use App\Core\Database;
use App\Core\SecurityHelper;
use App\Services\DocumentWorkflowService;
use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

class ThrottleTableMissingTest extends TestCase
{
    // Previous error_log target, restored after the test
    private string|false $previousErrorLog = false;

    // Drop the throttle table to simulate a database that has not run the migration
    protected function setUp(): void
    {
        parent::setUp();
        Database::getInstance()->getConnection()->exec('DROP TABLE attempt_throttles');
        $_SESSION = [];

        // Keep the expected "table missing" warnings out of the test output
        $this->previousErrorLog = ini_set('error_log', '/dev/null');
    }

    // Restore the session and the error_log target
    protected function tearDown(): void
    {
        $_SESSION = [];
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
        }
        parent::tearDown();
    }

    // A wrong login is handled normally and a correct one still succeeds
    public function test_login_fails_open_without_table(): void
    {
        $client = HttpClient::start();

        // Wrong password: an ordinary redirect back to /login, not a 500
        $client->get('/login');
        $bad = $client->post('/login', ['email' => 'cash.unit', 'password' => 'wrong']);
        $this->assertSame(302, $bad['status']);
        $this->assertStringContainsString('/login', $bad['headers']['location'] ?? '');
        $this->assertStringContainsString('These credentials do not match our records.', $client->get('/login')['body']);

        // Correct password: redirected away from the login page
        $client->get('/login');
        $good = $client->post('/login', ['email' => 'cash.unit', 'password' => TestDatabase::PASSWORD]);
        $this->assertSame(302, $good['status']);
        $this->assertStringNotContainsString('/login', $good['headers']['location'] ?? '/login');
    }

    // Wrong and correct PINs resolve without throwing, and the correct PIN signs
    public function test_pin_signing_fails_open_without_table(): void
    {
        $s = Fixtures::submit();
        Fixtures::advanceTo($s['id'], 'in_transit', ['Cash Unit']);
        $user = Fixtures::user('cash.unit');
        $_SESSION['user_id'] = $user->id;

        // A wrong PIN is not counted but must not crash resolution
        $this->assertSame('000000', SecurityHelper::resolvePin('000000'));

        // The correct PIN signs the receive
        $pin = SecurityHelper::resolvePin(TestDatabase::PIN);
        (new DocumentWorkflowService())->scanDocument($s['tracking_code'], $user, (string) $pin);

        $status = Database::getInstance()->query('SELECT status FROM documents WHERE id = :id', [':id' => $s['id']])->fetch()['status'];
        $this->assertSame('processing', $status);
    }
}
