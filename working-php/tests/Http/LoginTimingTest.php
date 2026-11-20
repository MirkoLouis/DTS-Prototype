<?php

namespace Tests\Http;

use App\Common\Database\Database;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Login does the same password-hashing work whether or not the username exists, so response time
 * does not reveal which usernames are real.
 */
class LoginTimingTest extends TestCase
{
    // Seconds one failed login takes for the given username
    private function failedLoginSeconds(string $username): float
    {
        // A fresh browser session per attempt, with the login page loaded for its CSRF token
        $client = HttpClient::start();
        $client->get('/login');
        $start = microtime(true);
        $client->post('/login', ['email' => $username, 'password' => 'definitely-wrong-password']);

        return microtime(true) - $start;
    }

    // An unknown username takes about as long as a wrong password for a real account
    public function test_unknown_username_takes_as_long_as_a_wrong_password(): void
    {
        // Two wrong passwords for a real account (MAX_FAILURES of them would trigger the lockout, which skips the check)
        $known = min($this->failedLoginSeconds('cash.unit'), $this->failedLoginSeconds('cash.unit'));

        // Three different usernames that do not exist
        $unknown = min(
            $this->failedLoginSeconds('no.such.user.a'),
            $this->failedLoginSeconds('no.such.user.b'),
            $this->failedLoginSeconds('no.such.user.c')
        );

        // Without a password check for unknown users this ratio is far below one half
        $this->assertGreaterThanOrEqual(
            0.5 * $known,
            $unknown,
            sprintf('unknown username answered in %.1f ms, wrong password in %.1f ms', $unknown * 1000, $known * 1000)
        );
    }

    // During the bcrypt to Argon2id migration a not-yet-upgraded account answers in the same time as an unknown username
    public function test_unknown_username_takes_as_long_as_a_wrong_password_for_a_bcrypt_account(): void
    {
        // Give an account that no other timing test uses a legacy bcrypt (cost 10) hash
        Database::getInstance()->query(
            'UPDATE users SET password = :p WHERE email = :e',
            [':p' => TestDatabase::LEGACY_BCRYPT_HASH, ':e' => 'records.unit']
        );

        // Two wrong passwords for the bcrypt account (below the lockout limit)
        $bcryptKnown = min($this->failedLoginSeconds('records.unit'), $this->failedLoginSeconds('records.unit'));

        // Three different usernames that do not exist
        $unknown = min(
            $this->failedLoginSeconds('no.such.user.d'),
            $this->failedLoginSeconds('no.such.user.e'),
            $this->failedLoginSeconds('no.such.user.f')
        );

        $message = sprintf('bcrypt account answered in %.1f ms, unknown username in %.1f ms', $bcryptKnown * 1000, $unknown * 1000);

        // Both directions: a fast refusal or a slow one would each reveal which kind of account exists
        $this->assertGreaterThanOrEqual(0.5 * $unknown, $bcryptKnown, $message);
        $this->assertGreaterThanOrEqual(0.5 * $bcryptKnown, $unknown, $message);
    }
}
