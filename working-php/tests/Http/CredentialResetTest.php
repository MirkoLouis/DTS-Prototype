<?php

namespace Tests\Http;

use App\Common\Database\Database;
use App\Modules\Auth\AttemptThrottleService;
use App\Modules\System\SystemAuditService;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * An admin resets a user's sign-in password, Security PIN or both from the Users page, after entering the
 * admin's own Security PIN; each reset is recorded in the system audit log.
 */
class CredentialResetTest extends TestCase
{
    // No audit log from an earlier test
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(SystemAuditService::path());
    }

    // Sign in as the admin on the users page so the CSRF token is current
    private function admin(): HttpClient
    {
        $client = HttpClient::start();
        $client->loginAs('admin');
        $client->get('/users');

        return $client;
    }

    // Post a reset for a seeded user
    private function reset(HttpClient $client, string $username, array $fields): array
    {
        return $client->post('/users/' . $this->fx['users'][$username] . '/reset-credentials', $fields);
    }

    // The users row for a username
    private function user(string $username): array
    {
        return Database::getInstance()->query('SELECT * FROM users WHERE email = :e', [':e' => $username])->fetch();
    }

    // Events in the audit log, oldest first
    private function auditEvents(): array
    {
        if (!is_file(SystemAuditService::path())) {
            return [];
        }

        return array_map(fn($line) => json_decode($line, true), file(SystemAuditService::path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    // Open key periods of a user
    private function openKeyPeriods(string $username): int
    {
        return (int) Database::getInstance()->query(
            'SELECT COUNT(*) c FROM user_public_key_histories WHERE user_id = :u AND (deactivated_at IS NULL OR deactivated_at > NOW())',
            [':u' => $this->fx['users'][$username]]
        )->fetch()['c'];
    }

    // Without a PIN nothing changes and nothing is recorded
    public function test_reset_without_a_pin_changes_nothing(): void
    {
        $before = $this->user('cash.unit');
        $this->reset($this->admin(), 'cash.unit', ['target' => 'both']);

        $this->assertSame($before, $this->user('cash.unit'));
        $this->assertSame([], $this->auditEvents());
    }

    // With a wrong PIN nothing changes, nothing is recorded, and the failure counts toward the lockout
    public function test_reset_with_a_wrong_pin_changes_nothing_and_counts_toward_the_lockout(): void
    {
        $client = $this->admin();
        $before = $this->user('cash.unit');
        for ($i = 0; $i < 3; $i++) {
            $this->reset($client, 'cash.unit', ['target' => 'both', 'pin' => '000000']);
            $client->get('/users');
        }

        $this->assertSame($before, $this->user('cash.unit'));
        $this->assertSame([], $this->auditEvents());
        $key = AttemptThrottleService::key('pin', (string) $this->fx['users']['admin']);
        $this->assertGreaterThan(0, AttemptThrottleService::secondsLocked($key, time()));

        // Locked out: even the right PIN is refused
        $this->reset($client, 'cash.unit', ['target' => 'both', 'pin' => TestDatabase::PIN]);
        $this->assertSame($before, $this->user('cash.unit'));
    }

    // Resetting the Security PIN clears only the key, and is recorded
    public function test_reset_pin_clears_only_the_key(): void
    {
        $before = $this->user('cash.unit');
        $response = $this->reset($this->admin(), 'cash.unit', ['target' => 'pin', 'pin' => TestDatabase::PIN]);

        $after = $this->user('cash.unit');
        $this->assertSame(302, $response['status']);
        $this->assertSame('/users', $response['headers']['location']);
        $this->assertNull($after['public_key']);
        $this->assertNull($after['private_key']);
        $this->assertNull($after['security_key_set_at']);
        $this->assertSame($before['password'], $after['password']);
        $this->assertSame($before['must_change_password'], $after['must_change_password']);
        $this->assertSame(0, $this->openKeyPeriods('cash.unit'));
        $this->assertSingleAudit('pin');
    }

    // Resetting the sign-in password sets the default password and the flag, and leaves the key alone
    public function test_reset_password_changes_only_the_password(): void
    {
        $before = $this->user('cash.unit');
        $this->reset($this->admin(), 'cash.unit', ['target' => 'password', 'pin' => TestDatabase::PIN]);

        $after = $this->user('cash.unit');
        $this->assertTrue(password_verify('password', $after['password']));
        $this->assertSame('1', (string) $after['must_change_password']);
        $this->assertSame($before['private_key'], $after['private_key']);
        $this->assertSame($before['public_key'], $after['public_key']);
        $this->assertSame(1, $this->openKeyPeriods('cash.unit'));
        $this->assertSingleAudit('password');
    }

    // After the reset the user signs in with "password" and gets the default-password reminder
    public function test_reset_user_signs_in_with_the_default_password_and_is_reminded(): void
    {
        $this->reset($this->admin(), 'cash.unit', ['target' => 'password', 'pin' => TestDatabase::PIN]);

        $client = HttpClient::start();
        $client->get('/login');
        $response = $client->post('/login', ['email' => 'cash.unit', 'password' => 'password']);
        $this->assertSame('/tasks', $response['headers']['location']);
        $messages = Database::getInstance()->query('SELECT message FROM notifications WHERE user_id = :u AND link = :l', [':u' => $this->fx['users']['cash.unit'], ':l' => '/account/password'])->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['You are using the default password.'], $messages);
    }

    // Both resets both
    public function test_reset_both(): void
    {
        $this->reset($this->admin(), 'cash.unit', ['target' => 'both', 'pin' => TestDatabase::PIN]);

        $after = $this->user('cash.unit');
        $this->assertTrue(password_verify('password', $after['password']));
        $this->assertSame('1', (string) $after['must_change_password']);
        $this->assertNull($after['private_key']);
        $this->assertSame(0, $this->openKeyPeriods('cash.unit'));
        $this->assertSingleAudit('both');
    }

    // An unknown target is refused even with the right PIN
    public function test_invalid_target_is_refused(): void
    {
        $client = $this->admin();
        $before = $this->user('cash.unit');
        foreach (['', 'everything', 'PIN', ['pin']] as $target) {
            $this->reset($client, 'cash.unit', ['target' => $target, 'pin' => TestDatabase::PIN]);
            $client->get('/users');
        }

        $this->assertSame($before, $this->user('cash.unit'));
        $this->assertSame([], $this->auditEvents());
    }

    // A user that does not exist is refused and not recorded
    public function test_unknown_user_is_refused(): void
    {
        $this->admin()->post('/users/999999/reset-credentials', ['target' => 'both', 'pin' => TestDatabase::PIN]);

        $this->assertSame([], $this->auditEvents());
    }

    // Only admins may reset
    public function test_non_admin_is_refused(): void
    {
        $client = HttpClient::start();
        $client->loginAs('personnel.unit');
        $client->get('/tasks');
        $before = $this->user('cash.unit');

        $response = $this->reset($client, 'cash.unit', ['target' => 'both', 'pin' => TestDatabase::PIN]);

        $this->assertSame(403, $response['status']);
        $this->assertSame($before, $this->user('cash.unit'));
        $this->assertSame([], $this->auditEvents());
    }

    // The old reset-signature route is gone and its action no longer works
    public function test_old_reset_signature_route_is_gone(): void
    {
        $before = $this->user('cash.unit');
        $response = $this->admin()->post('/users/' . $this->fx['users']['cash.unit'] . '/reset-signature', ['pin' => TestDatabase::PIN]);

        $this->assertSame(404, $response['status']);
        $this->assertSame($before, $this->user('cash.unit'));
    }

    // The reset modal, the PIN prompt and their script are part of the page content (in-app navigation swaps only the content)
    public function test_modal_and_script_are_inside_the_page_content(): void
    {
        $body = $this->admin()->get('/users')['body'];

        // The layout swaps only the #pjax-content container, which ends where <main> ends
        $start = strpos($body, '<div id="pjax-content">');
        $end = strpos($body, '</main>', (int) $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        foreach (['id="reset-credentials-modal"', 'initResetCredentialsModal', 'id="signing-modal"'] as $needle) {
            $position = strpos($body, $needle);
            $this->assertNotFalse($position, $needle);
            $this->assertGreaterThan($start, $position, $needle);
            $this->assertLessThan($end, $position, $needle);
        }

        // Three choices and a PIN-prompting form
        foreach (['value="password"', 'value="pin"', 'value="both"'] as $choice) {
            $this->assertStringContainsString($choice, $body);
        }
        $this->assertMatchesRegularExpression('/id="reset-credentials-form"[^>]*class="[^"]*sign-action/', $body);
        $this->assertFalse(strpos($body, '/reset-signature'));
    }

    // Every account row offers the reset, also accounts without a Security PIN
    public function test_every_active_row_offers_the_reset(): void
    {
        Database::getInstance()->query('UPDATE users SET public_key = NULL, private_key = NULL, security_key_set_at = NULL WHERE email = :e', [':e' => 'cash.unit']);
        $body = $this->admin()->get('/users')['body'];

        $this->assertSame(4, substr_count($body, 'data-reset-user-id='), 'one trigger per active account (4 fixture users)');
    }

    // Assert exactly one audit entry for the reset of cash.unit with the given target
    private function assertSingleAudit(string $target): void
    {
        $events = $this->auditEvents();
        $this->assertCount(1, $events);
        $this->assertSame('user_credentials_reset', $events[0]['event']);
        $this->assertSame([
            'target_user_id' => $this->fx['users']['cash.unit'],
            'target' => $target,
            'user_id' => $this->fx['users']['admin'],
        ], $events[0]['data']);
    }
}
