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

    // The message of a failed sign-in: it does not say which of username and password was wrong
    private const FAILED_MESSAGE = 'Wrong username or password. Please double-check and try again.';

    // The value of the username input on a login page (HTML-decoded as the browser would), or null when it has none
    private function usernameValue(string $page): ?string
    {
        preg_match('/<input id="email"[^>]*?value="([^"]*)"/s', $page, $match);

        return isset($match[1]) ? html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5) : null;
    }

    // The MAX_FAILURES-th wrong password in a minute shows the timeout message; the ones before show the ordinary one
    public function test_last_allowed_failed_login_shows_timeout_message(): void
    {
        $client = HttpClient::start();

        // The failures before the limit show the normal credentials error and keep the username
        for ($i = 1; $i < AttemptThrottleService::MAX_FAILURES; $i++) {
            $result = $this->attempt($client, 'cash', 'wrong');
            $this->assertStringContainsString(self::FAILED_MESSAGE, $result['page']);
            $this->assertStringNotContainsString(AttemptThrottleService::MESSAGE, $result['page']);
        }

        // The failure that reaches the limit locks; the lockout message is unchanged and the username is still kept
        $result = $this->attempt($client, 'cash', 'wrong');
        $this->assertFalse($this->loggedIn($result));
        $this->assertStringContainsString(AttemptThrottleService::MESSAGE, $result['page']);
        $this->assertSame('Too many failed attempts. Timed out for 1 minute.', AttemptThrottleService::MESSAGE);
        $this->assertSame('cash', $this->usernameValue($result['page']));
    }

    // A wrong password shows the message, keeps the username in the field and puts nothing in the password field
    public function test_wrong_password_keeps_the_username_and_never_echoes_the_password(): void
    {
        $client = HttpClient::start();

        $result = $this->attempt($client, 'cash', 'my-secret-guess');

        $this->assertStringContainsString(self::FAILED_MESSAGE, $result['page']);
        $this->assertSame('cash', $this->usernameValue($result['page']));
        $this->assertStringNotContainsString('my-secret-guess', $result['page']);
        $this->assertMatchesRegularExpression('/<input id="password"(?:(?!>).)*?\bautofocus\b/s', $result['page']);
        $this->assertDoesNotMatchRegularExpression('/<input id="email"(?:(?!>).)*?\bautofocus\b/s', $result['page']);
    }

    // An unknown username gets the identical message and keeps what was typed
    public function test_unknown_username_gets_the_same_message_and_keeps_the_username(): void
    {
        $client = HttpClient::start();

        $result = $this->attempt($client, 'no.such.user', 'whatever-password');

        $this->assertStringContainsString(self::FAILED_MESSAGE, $result['page']);
        $this->assertSame('no.such.user', $this->usernameValue($result['page']));
        $this->assertStringNotContainsString('whatever-password', $result['page']);
    }

    // A deactivated account gets the same message too
    public function test_deactivated_account_gets_the_same_message(): void
    {
        \App\Common\Database\Database::getInstance()->query('UPDATE users SET deactivated_at = NOW() WHERE email = :e', [':e' => 'cash']);
        $client = HttpClient::start();

        $result = $this->attempt($client, 'cash', TestDatabase::PASSWORD);

        $this->assertStringContainsString(self::FAILED_MESSAGE, $result['page']);
        $this->assertSame('cash', $this->usernameValue($result['page']));
    }

    // A username is escaped where it is put back into the field
    public function test_username_is_escaped_in_the_value_attribute(): void
    {
        $client = HttpClient::start();
        $typed = '"><script>alert(1)</script>';

        $result = $this->attempt($client, $typed, 'x');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $result['page']);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $result['page']);
        $this->assertSame($typed, $this->usernameValue($result['page']));
    }

    // The right password is refused while the timeout lasts
    public function test_correct_password_is_refused_while_locked(): void
    {
        $client = HttpClient::start();
        for ($i = 0; $i < AttemptThrottleService::MAX_FAILURES; $i++) {
            $this->attempt($client, 'cash', 'wrong');
        }

        $result = $this->attempt($client, 'cash', TestDatabase::PASSWORD);
        $this->assertFalse($this->loggedIn($result));
        $this->assertStringContainsString(AttemptThrottleService::MESSAGE, $result['page']);
    }

    // A successful login resets the failure count
    public function test_successful_login_clears_failures(): void
    {
        $client = HttpClient::start();

        // Failures just below the limit, then a success that must clear them
        for ($i = 1; $i < AttemptThrottleService::MAX_FAILURES; $i++) {
            $this->attempt($client, 'cash', 'wrong');
        }
        $success = $this->attempt($client, 'cash', TestDatabase::PASSWORD);
        $this->assertTrue($this->loggedIn($success));

        // Log out through the real form (POST with the page's CSRF token)
        $client->get($success['location']);
        $client->post('/logout', []);

        // Failures just below the limit again would lock only if the earlier ones still counted
        for ($i = 1; $i < AttemptThrottleService::MAX_FAILURES; $i++) {
            $this->attempt($client, 'cash', 'wrong');
        }
        $this->assertTrue($this->loggedIn($this->attempt($client, 'cash', TestDatabase::PASSWORD)));
    }
}
