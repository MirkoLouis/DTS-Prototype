<?php

namespace Tests\Http;

use App\Common\Database\Database;
use App\Modules\Auth\AttemptThrottleService;
use App\Modules\System\SystemAuditService;
use Tests\Support\HttpClient;
use Tests\Support\PageMessages;
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
        for ($i = 0; $i < AttemptThrottleService::MAX_FAILURES; $i++) {
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
        $this->assertSame('argon2id', password_get_info($after['password'])['algoName']);
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

    // A signed-in browser for a seeded user
    private function signedIn(string $username): HttpClient
    {
        $client = HttpClient::start();
        $client->loginAs($username);

        return $client;
    }

    // Whether the browser's next page request is sent to the login page, which then explains why
    private function isSentToLogin(HttpClient $client): bool
    {
        $response = $client->get('/tasks');
        if (($response['headers']['location'] ?? '') !== '/login') {
            return false;
        }

        return str_contains($client->get('/login')['body'], 'Your password was reset. Please sign in again.');
    }

    // A reset of the sign-in password ends every session of that user and the user can sign in again with the default password
    public function test_password_reset_ends_the_users_sessions(): void
    {
        $first = $this->signedIn('cash.unit');
        $second = $this->signedIn('cash.unit');
        $this->reset($this->admin(), 'cash.unit', ['target' => 'password', 'pin' => TestDatabase::PIN]);

        $this->assertTrue($this->isSentToLogin($first));
        $this->assertTrue($this->isSentToLogin($second));

        $again = HttpClient::start();
        $again->get('/login');
        $response = $again->post('/login', ['email' => 'cash.unit', 'password' => 'password']);
        $this->assertSame('/tasks', $response['headers']['location']);
    }

    // GET a page the way the in-app navigation script does (X-PJAX header), then stop sending the header
    private function pjaxGet(HttpClient $client, string $path): array
    {
        $client->withHeader('X-PJAX', 'true');
        $response = $client->get($path);
        $client->withHeader('X-PJAX', null);

        return $response;
    }

    // After a password reset, a PJAX navigation gets no redirect (the target is in X-PJAX-Location), so the login
    // page (loaded next) still has the flash message; a followed redirect would have consumed it inside fetch()
    public function test_pjax_request_of_an_ended_session_is_not_redirected_and_keeps_the_message(): void
    {
        $session = $this->signedIn('cash.unit');
        $this->reset($this->admin(), 'cash.unit', ['target' => 'password', 'pin' => TestDatabase::PIN]);

        $response = $this->pjaxGet($session, '/tasks');

        $this->assertSame(204, $response['status']);
        $this->assertArrayNotHasKey('location', $response['headers']);
        $this->assertSame('/login', $response['headers']['x-pjax-location']);
        PageMessages::assertLoginAlert($session->get('/login')['body'], 'Your password was reset. Please sign in again.');
    }

    // The same for a session that expired by idling
    public function test_pjax_request_of_an_idle_expired_session_keeps_the_expiry_message(): void
    {
        $session = $this->signedIn('cash.unit');
        $file = \App\Common\Http\SessionService::directory() . '/sess_' . $session->cookie(session_name());
        $aged = preg_replace('/last_activity\|i:\d+;/', 'last_activity|i:' . (time() - 43201) . ';', (string) file_get_contents($file));
        file_put_contents($file, $aged);

        $response = $this->pjaxGet($session, '/tasks');

        $this->assertSame(204, $response['status']);
        $this->assertArrayNotHasKey('location', $response['headers']);
        $this->assertSame('/login', $response['headers']['x-pjax-location']);
        PageMessages::assertLoginAlert($session->get('/login')['body'], 'session has expired');
    }

    // A plain request of an ended session is still redirected to the login page, which shows the message
    public function test_plain_request_of_an_ended_session_is_still_redirected(): void
    {
        $session = $this->signedIn('cash.unit');
        $this->reset($this->admin(), 'cash.unit', ['target' => 'password', 'pin' => TestDatabase::PIN]);

        $response = $session->get('/tasks');

        $this->assertSame(302, $response['status']);
        $this->assertSame('/login', $response['headers']['location']);
        PageMessages::assertLoginAlert($session->get('/login')['body'], 'Your password was reset. Please sign in again.');
    }

    // A valid session is served normally to a PJAX request
    public function test_pjax_request_of_a_valid_session_gets_the_page(): void
    {
        $session = $this->signedIn('cash.unit');

        $this->assertSame(200, $this->pjaxGet($session, '/tasks')['status']);
    }

    // A reset of the Security PIN alone leaves sessions working
    public function test_pin_reset_keeps_the_users_sessions(): void
    {
        $session = $this->signedIn('cash.unit');
        $this->reset($this->admin(), 'cash.unit', ['target' => 'pin', 'pin' => TestDatabase::PIN]);

        $this->assertSame(200, $session->get('/tasks')['status']);
    }

    // Resetting both ends the sessions
    public function test_both_reset_ends_the_users_sessions(): void
    {
        $session = $this->signedIn('cash.unit');
        $this->reset($this->admin(), 'cash.unit', ['target' => 'both', 'pin' => TestDatabase::PIN]);

        $this->assertTrue($this->isSentToLogin($session));
    }

    // An admin who resets their own sign-in password is signed out like anyone else
    public function test_admin_resetting_their_own_password_is_signed_out(): void
    {
        $client = $this->admin();
        $this->reset($client, 'admin', ['target' => 'password', 'pin' => TestDatabase::PIN]);

        $this->assertSame('/login', $client->get('/users')['headers']['location'] ?? '');
    }

    // A session from before the column existed (no stored value) keeps working while the account's counter is 0
    public function test_session_without_a_stored_version_keeps_working_while_the_counter_is_zero(): void
    {
        $session = $this->signedIn('cash.unit');
        $file = \App\Common\Http\SessionService::directory() . '/sess_' . $session->cookie(session_name());
        $stored = (string) file_get_contents($file);
        $stripped = preg_replace('/session_version\|i:\d+;/', '', $stored);
        $this->assertNotSame($stored, $stripped, 'the session stores the account version at sign-in');
        file_put_contents($file, $stripped);

        $this->assertSame(200, $session->get('/tasks')['status']);

        // Once the counter moves, the same session ends
        Database::getInstance()->query('UPDATE users SET session_version = 1 WHERE email = :e', [':e' => 'cash.unit']);
        $this->assertTrue($this->isSentToLogin($session));
    }

    // The old reset-signature route is gone and its action no longer works
    public function test_old_reset_signature_route_is_gone(): void
    {
        $before = $this->user('cash.unit');
        $response = $this->admin()->post('/users/' . $this->fx['users']['cash.unit'] . '/reset-signature', ['pin' => TestDatabase::PIN]);

        $this->assertSame(404, $response['status']);
        $this->assertSame($before, $this->user('cash.unit'));
    }

    // The reset modal, the PIN prompt and the Reset buttons their script attaches to are part of the page content
    // (in-app navigation swaps only the content); the script files themselves are loaded once by the layout
    public function test_modal_and_script_are_inside_the_page_content(): void
    {
        $body = $this->admin()->get('/users')['body'];

        // The layout swaps only the #pjax-content container, which ends where <main> ends
        $start = strpos($body, '<div id="pjax-content">');
        $end = strpos($body, '</main>', (int) $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        foreach (['id="reset-credentials-modal"', 'class="reset-credentials-trigger', 'data-reset-user-id=', 'id="signing-modal"'] as $needle) {
            $position = strpos($body, $needle);
            $this->assertNotFalse($position, $needle);
            $this->assertGreaterThan($start, $position, $needle);
            $this->assertLessThan($end, $position, $needle);
        }

        // The behaviour comes from script files in the head (the reset dialog and the PIN prompt), none inline
        foreach (['/js/pages/users.js', '/js/components/sign-actions.js', '/js/components/signing-modal.js'] as $script) {
            $this->assertLessThan(strpos($body, '</head>'), strpos($body, '<script src="' . $script . '" defer></script>'), $script);
        }
        $this->assertFalse(strpos($body, 'initResetCredentialsModal'));

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
