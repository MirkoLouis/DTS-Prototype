<?php

namespace Tests\Http;

use App\Common\Database\Database;
use App\Modules\Auth\AttemptThrottleService;
use App\Modules\Integrity\DigitalSignatureService;
use App\Modules\Notifications\NotificationService;
use Tests\Support\Fixtures;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Self-service password change, the password reminder, and the 10-character rule for passwords and Security PINs.
 */
class AccountPasswordTest extends TestCase
{
    private const NEW_PASSWORD = 'Another-Pass-2027';
    private const REMINDER_LINK = '/account/password';

    // Sign in as a seeded user and load the change-password page so the CSRF token is current
    private function signedIn(string $username = 'cash.unit'): HttpClient
    {
        $client = HttpClient::start();
        $client->loginAs($username);
        $client->get('/account/password');

        return $client;
    }

    // Sign in through the login form with explicit credentials and load the landing page
    private function signInWith(string $username, string $password): HttpClient
    {
        $client = HttpClient::start();
        $client->get('/login');
        $response = $client->post('/login', ['email' => $username, 'password' => $password]);
        $client->get($response['headers']['location']);

        return $client;
    }

    // Post the change-password form (loading the form first keeps the CSRF token current)
    private function change(HttpClient $client, string $current, string $new, ?string $confirmation = null): array
    {
        $client->get('/account/password');

        return $client->post('/account/password', [
            'current_password' => $current,
            'new_password' => $new,
            'new_password_confirmation' => $confirmation ?? $new,
        ]);
    }

    // The users row for a username
    private function user(string $username): array
    {
        return Database::getInstance()->query('SELECT * FROM users WHERE email = :e', [':e' => $username])->fetch();
    }

    // Give a user a sign-in password and a reminder flag directly in the database
    private function setPassword(string $username, string $password, int $flag = 0): void
    {
        Database::getInstance()->query('UPDATE users SET password = :p, must_change_password = :f WHERE email = :e', [
            ':p' => password_hash($password, PASSWORD_BCRYPT),
            ':f' => $flag,
            ':e' => $username,
        ]);
    }

    // Whether a fresh browser can sign in with these credentials
    private function canSignIn(string $username, string $password): bool
    {
        $client = HttpClient::start();
        $client->get('/login');
        $response = $client->post('/login', ['email' => $username, 'password' => $password]);

        return $response['status'] === 302 && !str_contains($response['headers']['location'] ?? '', '/login');
    }

    // The password reminders a user has, oldest first
    private function reminders(string $username): array
    {
        return Database::getInstance()->query(
            'SELECT * FROM notifications WHERE user_id = :u AND link = :l ORDER BY id',
            [':u' => $this->fx['users'][$username], ':l' => self::REMINDER_LINK]
        )->fetchAll();
    }

    // Text of the page's flash/alert boxes after a request
    private function pageAfter(HttpClient $client, string $path = '/account/password'): string
    {
        return $client->get($path)['body'];
    }

    // Submit the Security PIN form (a fresh page load first keeps the CSRF token current)
    private function setPin(HttpClient $client, string $pin, ?string $confirmation = null): array
    {
        $client->get('/tasks');

        return $client->post('/security-key', ['pin' => $pin, 'pin_confirm' => $confirmation ?? $pin]);
    }

    // Remove a user's signing key, as an admin reset does, so the account may create a PIN
    private function clearKey(string $username = 'cash.unit'): void
    {
        Database::getInstance()->query('UPDATE users SET public_key = NULL, private_key = NULL, security_key_set_at = NULL WHERE email = :e', [':e' => $username]);
    }

    // Wrong or repeated hits recorded for a throttle scope and user
    private function throttleRow(string $scope, string $username = 'cash.unit'): array|false
    {
        $key = AttemptThrottleService::key($scope, (string) $this->fx['users'][$username]);

        return Database::getInstance()->query('SELECT failures, locked_until FROM attempt_throttles WHERE throttle_key = :k', [':k' => $key])->fetch();
    }

    // ---------- A. self-service password change ----------

    // A signed-in user reaches the page and finds the menu entry and both explanations
    public function test_page_is_reachable_and_the_menu_has_the_entry(): void
    {
        $client = $this->signedIn();
        $page = $client->get('/account/password');

        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('part of your sign-in credentials; it proves who you are when you log in.', $page['body']);
        $this->assertStringContainsString('Using the same passcode for sign-in and for signing is a security risk: anyone who learns one would have both.', $page['body']);

        // The user dropdown on any page links to it
        $menu = $client->get('/tasks')['body'];
        $this->assertMatchesRegularExpression('/<a href="\/account\/password"[^>]*>\s*Change password\s*<\/a>/', $menu);
    }

    // A signed-out visitor is sent to the login page
    public function test_signed_out_visitor_is_redirected_to_login(): void
    {
        $client = HttpClient::start();

        $this->assertSame('/login', $client->get('/account/password')['headers']['location'] ?? '');

        // The login page gives the visitor a CSRF token, so the POST reaches the authentication check
        $client->get('/login');
        $this->assertSame('/login', $client->post('/account/password', ['current_password' => 'x', 'new_password' => 'y', 'new_password_confirmation' => 'y'])['headers']['location'] ?? '');
    }

    // A successful change stores the new password, clears the flag and the reminders, and says so
    public function test_successful_change(): void
    {
        $this->setPassword('cash.unit', TestDatabase::PASSWORD, 1);
        (new NotificationService())->notifyUser($this->fx['users']['cash.unit'], 'You are using the default password.', 'm', 'warning', self::REMINDER_LINK, 'Change password');
        $client = $this->signedIn();

        $response = $this->change($client, TestDatabase::PASSWORD, self::NEW_PASSWORD);

        $this->assertSame(302, $response['status']);
        $this->assertSame('/tasks', $response['headers']['location']);
        $this->assertSame('0', (string) $this->user('cash.unit')['must_change_password']);
        $this->assertSame([], $this->reminders('cash.unit'));
        $this->assertStringContainsString('Your password has been changed.', $client->get('/tasks')['body']);
        $this->assertTrue($this->canSignIn('cash.unit', self::NEW_PASSWORD));
        $this->assertFalse($this->canSignIn('cash.unit', TestDatabase::PASSWORD));
    }

    // A wrong current password changes nothing
    public function test_wrong_current_password_changes_nothing(): void
    {
        $client = $this->signedIn();
        $before = $this->user('cash.unit')['password'];

        $this->change($client, 'not-the-password', self::NEW_PASSWORD);

        $this->assertSame($before, $this->user('cash.unit')['password']);
        $this->assertStringContainsString('current password is not correct', $this->pageAfter($client));
    }

    // Three wrong current passwords lock out a fourth attempt, even with the correct current password
    public function test_three_wrong_attempts_lock_out_the_correct_password(): void
    {
        $client = $this->signedIn();
        for ($i = 0; $i < 3; $i++) {
            $this->change($client, 'not-the-password', self::NEW_PASSWORD);
        }

        $this->change($client, TestDatabase::PASSWORD, self::NEW_PASSWORD);

        $this->assertTrue(password_verify(TestDatabase::PASSWORD, $this->user('cash.unit')['password']));
        $this->assertStringContainsString(AttemptThrottleService::MESSAGE, $this->pageAfter($client));
    }

    // A correct change forgets earlier wrong attempts
    public function test_success_clears_earlier_failures(): void
    {
        $client = $this->signedIn();
        $this->change($client, 'not-the-password', self::NEW_PASSWORD);
        $this->change($client, 'not-the-password', self::NEW_PASSWORD);
        $this->change($client, TestDatabase::PASSWORD, self::NEW_PASSWORD);

        $key = AttemptThrottleService::key('account-password', (string) $this->fx['users']['cash.unit']);
        $row = Database::getInstance()->query('SELECT failures FROM attempt_throttles WHERE throttle_key = :k', [':k' => $key])->fetch();
        $this->assertFalse($row);
    }

    // Each refused new password leaves the stored password alone and names the reason
    public function test_refused_new_passwords(): void
    {
        $cases = [
            'shorter than 10' => ['Short-9ch', 'Short-9ch', 'at least 10 characters'],
            'confirmation mismatch' => [self::NEW_PASSWORD, 'Different-Pass-1', 'do not match'],
            'same as current' => [TestDatabase::PASSWORD, TestDatabase::PASSWORD, 'different from the current'],
            'the word password' => ['password', 'password', 'at least 10 characters'],
        ];

        foreach ($cases as $name => [$new, $confirmation, $message]) {
            $client = $this->signedIn();
            $before = $this->user('cash.unit')['password'];

            $this->change($client, TestDatabase::PASSWORD, $new, $confirmation);

            $this->assertSame($before, $this->user('cash.unit')['password'], $name);
            $this->assertStringContainsString($message, $this->pageAfter($client), $name);
        }
    }

    // A new password equal to the Security PIN is refused, and the check never touches the PIN lockout
    public function test_new_password_equal_to_the_pin_is_refused_without_counting_a_pin_failure(): void
    {
        $pin = 'my-long-pin-1234';
        $client = $this->signedIn();
        $this->clearKey();
        $this->setPin($client, $pin);
        $before = $this->user('cash.unit')['password'];

        // As many refusals as the rate limit allows: none of them may count as a wrong PIN
        for ($i = 0; $i < 3; $i++) {
            $this->change($client, TestDatabase::PASSWORD, $pin);
        }

        $this->assertSame($before, $this->user('cash.unit')['password']);
        $this->assertStringContainsString('different from your Security PIN', $this->pageAfter($client));

        // The PIN still signs: it is neither locked out nor recorded as failed
        $userId = $this->fx['users']['cash.unit'];
        $pinKey = AttemptThrottleService::key('pin', (string) $userId);
        $this->assertSame(0, AttemptThrottleService::secondsLocked($pinKey, time()));
        $this->assertFalse(Database::getInstance()->query('SELECT 1 FROM attempt_throttles WHERE throttle_key = :k', [':k' => $pinKey])->fetch());
        $this->assertNotSame('', DigitalSignatureService::signAction($userId, $pin, 'still-signs'));
    }

    // A password different from the PIN is accepted
    public function test_new_password_different_from_the_pin_is_accepted(): void
    {
        $client = $this->signedIn();
        $this->clearKey();
        $this->setPin($client, 'my-long-pin-1234');
        $this->change($client, TestDatabase::PASSWORD, self::NEW_PASSWORD);

        $this->assertTrue($this->canSignIn('cash.unit', self::NEW_PASSWORD));
    }

    // At most three changes per minute and account: the fourth is refused with the timeout message
    public function test_fourth_change_in_a_minute_is_refused(): void
    {
        $client = $this->signedIn();
        $passwords = [TestDatabase::PASSWORD, 'Chain-Pass-0001', 'Chain-Pass-0002', 'Chain-Pass-0003'];
        for ($i = 0; $i < 3; $i++) {
            $this->change($client, $passwords[$i], $passwords[$i + 1]);
        }
        $this->assertTrue(password_verify($passwords[3], $this->user('cash.unit')['password']));

        $this->change($client, $passwords[3], 'Chain-Pass-0004');

        $this->assertTrue(password_verify($passwords[3], $this->user('cash.unit')['password']));
        $this->assertStringContainsString(AttemptThrottleService::MESSAGE, $this->pageAfter($client));
    }

    // Three refusals for "equals the Security PIN" lock out the fourth POST, even with a valid different password
    public function test_three_pin_match_refusals_lock_out_the_next_change(): void
    {
        $pin = 'my-long-pin-1234';
        $client = $this->signedIn();
        $this->clearKey();
        $this->setPin($client, $pin);
        for ($i = 0; $i < 3; $i++) {
            $this->change($client, TestDatabase::PASSWORD, $pin);
        }

        $this->change($client, TestDatabase::PASSWORD, self::NEW_PASSWORD);

        $this->assertTrue(password_verify(TestDatabase::PASSWORD, $this->user('cash.unit')['password']));
        $this->assertStringContainsString(AttemptThrottleService::MESSAGE, $this->pageAfter($client));
    }

    // A successful change clears the wrong-current-password count but not the change-rate count
    public function test_success_keeps_the_change_rate_count(): void
    {
        $client = $this->signedIn();
        $this->change($client, TestDatabase::PASSWORD, self::NEW_PASSWORD);

        $this->assertFalse($this->throttleRow('account-password'));
        $this->assertSame(1, (int) $this->throttleRow('account-password-outcome')['failures']);
    }

    // A successful change gives the browser a new session id
    public function test_session_cookie_changes_after_a_successful_change(): void
    {
        $client = $this->signedIn();
        $before = $client->cookie(session_name());

        $this->change($client, TestDatabase::PASSWORD, self::NEW_PASSWORD);

        $this->assertNotNull($client->cookie(session_name()));
        $this->assertNotSame($before, $client->cookie(session_name()));
    }

    // ---------- B. reminder ----------

    // Signing in with the default password flags the account and leaves exactly one reminder with the link
    public function test_default_password_login_sets_flag_and_one_reminder(): void
    {
        $this->setPassword('cash.unit', 'password');

        $this->assertTrue($this->canSignIn('cash.unit', 'password'));
        $this->assertSame('1', (string) $this->user('cash.unit')['must_change_password']);
        $reminders = $this->reminders('cash.unit');
        $this->assertCount(1, $reminders);
        $this->assertSame('You are using the default password.', $reminders[0]['title']);
        $this->assertSame('You are using the default password.', $reminders[0]['message']);
        $this->assertSame('Change password', $reminders[0]['link_label']);

        // Signing in again replaces the reminder instead of adding another
        $this->assertTrue($this->canSignIn('cash.unit', 'password'));
        $this->assertCount(1, $this->reminders('cash.unit'));
    }

    // The reminder is shown with its link below the message wherever notifications are rendered
    public function test_reminder_is_rendered_with_its_link(): void
    {
        $this->setPassword('cash.unit', 'password');
        $client = $this->signInWith('cash.unit', 'password');

        $body = $client->get('/tasks')['body'];

        $this->assertMatchesRegularExpression('/You are using the default password\.\s*<\/p>\s*<a class="notification-link[^"]*"[^>]*href="\/account\/password"[^>]*>\s*Change password\s*<\/a>/', $body);
        $api = json_decode($client->get('/api/notifications/unread')['body'], true);
        $this->assertSame(self::REMINDER_LINK, $api['notifications'][0]['link']);
    }

    // A user with their own password and no flag gets no reminder
    public function test_own_password_gets_no_reminder(): void
    {
        $this->assertTrue($this->canSignIn('cash.unit', TestDatabase::PASSWORD));

        $this->assertSame([], $this->reminders('cash.unit'));
        $this->assertSame('0', (string) $this->user('cash.unit')['must_change_password']);
    }

    // A flagged account with its own password gets the administrator wording
    public function test_flagged_own_password_gets_the_administrator_reminder(): void
    {
        $this->setPassword('cash.unit', TestDatabase::PASSWORD, 1);

        $this->assertTrue($this->canSignIn('cash.unit', TestDatabase::PASSWORD));
        $reminders = $this->reminders('cash.unit');
        $this->assertCount(1, $reminders);
        $this->assertSame('Your password was set by an administrator.', $reminders[0]['message']);
    }

    // Nothing is forced: a flagged user opens other pages freely
    public function test_flagged_user_is_not_forced_to_change_the_password(): void
    {
        $this->setPassword('cash.unit', 'password');
        $client = $this->signInWith('cash.unit', 'password');

        $this->assertSame(200, $client->get('/tasks')['status']);
        $this->assertSame(200, $client->get('/account/password')['status']);
    }

    // A notification link must be an internal path; anything else is stored as no link
    public function test_notification_links_are_internal_paths_only(): void
    {
        $id = $this->fx['users']['cash.unit'];
        $service = new NotificationService();
        $bad = ['https://evil.example/x', '//evil.example', '//evil', 'https://x', 'javascript:alert(1)', '\\evil', "/\t/evil.example", "/\\evil", "/a b", "/a\nb", "/ok\\path", '/'. "\x7f"];
        foreach ($bad as $link) {
            $this->assertNull(NotificationService::safeLink($link), json_encode($link));
        }

        // Stored too (a user keeps at most 10 notifications, so only a few are stored)
        foreach (array_slice($bad, 0, 4) as $link) {
            $service->notifyUser($id, 'T', 'M', 'info', $link, 'Open');
        }
        $service->notifyUser($id, 'T', 'M', 'info', '/account/password', 'Open');

        $links = Database::getInstance()->query('SELECT link FROM notifications WHERE user_id = :u ORDER BY id', [':u' => $id])->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([null, null, null, null, '/account/password'], $links);
        $this->assertSame('/account/password', NotificationService::safeLink('/account/password'));
    }

    // Insert a notification row directly, bypassing the service's link check
    private function insertRawNotification(string $username, ?string $link, ?string $label): void
    {
        Database::getInstance()->query(
            "INSERT INTO notifications (user_id, title, message, type, link, link_label) VALUES (:u, 'Raw title', 'Raw message', 'info', :l, :b)",
            [':u' => $this->fx['users'][$username], ':l' => $link, ':b' => $label]
        );
    }

    // A stored link that is not an internal path is not rendered as a link
    public function test_a_bad_stored_link_renders_without_a_link(): void
    {
        $this->insertRawNotification('cash.unit', 'javascript:alert(1)', 'Click');
        $this->insertRawNotification('cash.unit', "/\t/evil.example", 'Click2');
        $client = HttpClient::start();
        $client->loginAs('cash.unit');
        $body = $client->get('/tasks')['body'];

        $this->assertStringContainsString('Raw message', $body);
        $this->assertStringNotContainsString('javascript:alert', $body);
        $this->assertStringNotContainsString('evil.example', $body);
        $this->assertStringNotContainsString('class="notification-link', $body);
    }

    // The link label is escaped when rendered
    public function test_the_link_label_is_escaped(): void
    {
        $this->insertRawNotification('cash.unit', '/account/password', '<b>bold</b>');
        $client = HttpClient::start();
        $client->loginAs('cash.unit');
        $body = $client->get('/tasks')['body'];

        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $body);
        $this->assertStringNotContainsString('<b>bold</b>', $body);
    }

    // A failing reminder is logged, not fatal: the sign-in still succeeds
    public function test_reminder_failure_does_not_fail_the_sign_in(): void
    {
        $this->setPassword('cash.unit', 'password');
        Database::getInstance()->getConnection()->exec('ALTER TABLE notifications DROP COLUMN link_label');

        $client = HttpClient::start();
        $client->get('/login');
        $response = $client->post('/login', ['email' => 'cash.unit', 'password' => 'password']);

        $this->assertSame('/tasks', $response['headers']['location'] ?? '');
    }

    // ---------- D. admin-created accounts and edit page ----------

    // An admin creates an account without a password: it starts with the default one, flagged
    public function test_admin_created_account_starts_with_the_default_password(): void
    {
        $admin = HttpClient::start();
        $admin->loginAs('admin');
        $this->assertStringNotContainsString('name="password"', $admin->get('/users/create')['body']);

        $admin->post('/users', ['name' => 'New Person', 'email' => 'new.person', 'role' => 'staff', 'department_id' => $this->fx['departments']['Cash Unit']]);

        $row = $this->user('new.person');
        $this->assertTrue(password_verify('password', $row['password']));
        $this->assertSame('1', (string) $row['must_change_password']);
        $this->assertTrue($this->canSignIn('new.person', 'password'));
        $created = Database::getInstance()->query('SELECT message FROM notifications WHERE user_id = :u AND link = :l', [':u' => (int) $row['id'], ':l' => self::REMINDER_LINK])->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['You are using the default password.'], $created);
    }

    // The edit page has no password fields, and a posted password field is ignored
    public function test_edit_page_has_no_password_fields_and_ignores_a_posted_password(): void
    {
        $id = $this->fx['users']['cash.unit'];
        $admin = HttpClient::start();
        $admin->loginAs('admin');

        $this->assertStringNotContainsString('name="password', $admin->get("/users/{$id}/edit")['body']);

        $before = $this->user('cash.unit')['password'];
        $admin->post("/users/{$id}/update", [
            'name' => 'Cash Unit Staff', 'email' => 'cash.unit', 'role' => 'staff', 'department_id' => $this->fx['departments']['Cash Unit'],
            'password' => 'sneaky-new-password', 'password_confirmation' => 'sneaky-new-password',
        ]);

        $this->assertSame($before, $this->user('cash.unit')['password']);
    }

    // ---------- C. Security PIN rule ----------

    // A new PIN shorter than 10 characters is refused and the key stays
    public function test_short_pin_is_refused(): void
    {
        $client = $this->signedIn();
        $this->clearKey();

        $this->setPin($client, '123456789');

        $this->assertNull($this->user('cash.unit')['private_key']);
        $this->assertStringContainsString('at least 10 characters', $client->get('/tasks')['body']);
    }

    // A new PIN equal to the sign-in password is refused and no key is created
    public function test_pin_equal_to_the_sign_in_password_is_refused(): void
    {
        $client = $this->signedIn();
        $this->clearKey();

        $this->setPin($client, TestDatabase::PASSWORD);

        $this->assertNull($this->user('cash.unit')['private_key']);
        $this->assertStringContainsString('different from your sign-in password', $client->get('/tasks')['body']);
    }

    // An account that already has a key cannot be re-keyed through the form: the key is replaced only by an admin reset
    public function test_pin_form_refuses_an_account_that_already_has_a_key(): void
    {
        $client = $this->signedIn();
        $before = $this->user('cash.unit');

        $this->setPin($client, 'a-brand-new-pin-123');

        $after = $this->user('cash.unit');
        $this->assertSame($before['public_key'], $after['public_key']);
        $this->assertSame($before['private_key'], $after['private_key']);
        $this->assertStringContainsString('Your Security PIN is already set. Ask an administrator to reset it.', $client->get('/tasks')['body']);
    }

    // The "equals the sign-in password" refusal counts as a failed attempt: the fourth attempt is locked out first
    public function test_three_pin_equals_password_refusals_lock_out_the_fourth_attempt(): void
    {
        $client = $this->signedIn();
        $this->clearKey();
        for ($i = 0; $i < 3; $i++) {
            $this->setPin($client, TestDatabase::PASSWORD);
        }

        $this->setPin($client, 'a-brand-new-pin-123');

        $this->assertNull($this->user('cash.unit')['private_key']);
        $this->assertStringContainsString(AttemptThrottleService::MESSAGE, $client->get('/tasks')['body']);
    }

    // A PIN of 10 or more characters (any characters) is accepted and signs
    public function test_long_pin_is_accepted_and_signs(): void
    {
        $client = $this->signedIn();
        $this->clearKey();
        $pin = 'pin with spaces & symbols!';

        $this->setPin($client, $pin);

        $userId = $this->fx['users']['cash.unit'];
        $signature = DigitalSignatureService::signAction($userId, $pin, 'message');
        $publicKey = base64_decode($this->user('cash.unit')['public_key']);
        $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode($signature), 'message', $publicKey));
    }

    // The setup prompt states the length rule, the vocabulary sentence and the shared note
    public function test_pin_setup_prompt_text(): void
    {
        $client = HttpClient::start();
        $client->loginAs('cash.unit');
        Database::getInstance()->query('UPDATE users SET public_key = NULL, private_key = NULL, security_key_set_at = NULL WHERE email = :e', [':e' => 'cash.unit']);
        $body = $client->get('/tasks')['body'];

        $this->assertStringNotContainsString('6-digit', $body);
        $this->assertStringContainsString('at least 10 characters', $body);
        $this->assertStringContainsString('a second passcode that unlocks your signing key; you enter it to sign an action on a document.', $body);
        $this->assertStringContainsString('Using the same passcode for sign-in and for signing is a security risk: anyone who learns one would have both.', $body);
        $this->assertSame(0, preg_match('/name="pin"[^>]*minlength="6"/', $body));
    }

    // An existing 6-character PIN keeps signing: nothing re-validates the length when a PIN is used
    public function test_existing_six_character_pin_still_signs(): void
    {
        $this->assertSame(6, strlen(TestDatabase::PIN));
        $document = Fixtures::submit();

        Fixtures::advanceTo($document['id'], 'in_transit');

        $this->assertNotSame('', DigitalSignatureService::signAction($this->fx['users']['records.unit'], TestDatabase::PIN, 'six-character-pin'));
        $status = Database::getInstance()->query('SELECT status FROM documents WHERE id = :id', [':id' => $document['id']])->fetch()['status'];
        $this->assertSame('in_transit', $status);
    }
}
