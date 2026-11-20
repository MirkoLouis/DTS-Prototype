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

    // Run the measurement; if the ratio check fails, measure once more and fail only if both runs fail
    private function assertSimilarTiming(callable $measure, string $what): void
    {
        $runs = [];

        // At most two attempts; a pass on either ends the loop
        foreach ([1, 2] as $attempt) {
            [$known, $unknown] = $measure();
            $runs[] = sprintf('run %d: %s %.1f ms, unknown username %.1f ms', $attempt, $what, $known * 1000, $unknown * 1000);

            // Both directions: a fast refusal or a slow one would each reveal which kind of account exists
            if ($unknown >= 0.5 * $known && $known >= 0.5 * $unknown) {
                $this->addToAssertionCount(1);
                return;
            }
        }

        $this->fail('timing differs in both runs (' . implode('; ', $runs) . ')');
    }

    // An unknown username takes about as long as a wrong password for a real account
    public function test_unknown_username_takes_as_long_as_a_wrong_password(): void
    {
        $this->assertSimilarTiming(function (): array {
            // Two wrong passwords for a real account (MAX_FAILURES of them would trigger the lockout, which skips the check)
            $known = min($this->failedLoginSeconds('cash.unit'), $this->failedLoginSeconds('cash.unit'));

            // Three different usernames that do not exist
            $unknown = min(
                $this->failedLoginSeconds('no.such.user.a'),
                $this->failedLoginSeconds('no.such.user.b'),
                $this->failedLoginSeconds('no.such.user.c')
            );

            return [$known, $unknown];
        }, 'wrong password');
    }

    // During the bcrypt to Argon2id migration a not-yet-upgraded account answers in the same time as an unknown username
    public function test_unknown_username_takes_as_long_as_a_wrong_password_for_a_bcrypt_account(): void
    {
        // Give an account that no other timing test uses a legacy bcrypt (cost 10) hash
        Database::getInstance()->query(
            'UPDATE users SET password = :p WHERE email = :e',
            [':p' => TestDatabase::LEGACY_BCRYPT_HASH, ':e' => 'records.unit']
        );

        $this->assertSimilarTiming(function (): array {
            // Two wrong passwords for the bcrypt account (below the lockout limit)
            $bcryptKnown = min($this->failedLoginSeconds('records.unit'), $this->failedLoginSeconds('records.unit'));

            // Three different usernames that do not exist
            $unknown = min(
                $this->failedLoginSeconds('no.such.user.d'),
                $this->failedLoginSeconds('no.such.user.e'),
                $this->failedLoginSeconds('no.such.user.f')
            );

            return [$bcryptKnown, $unknown];
        }, 'bcrypt account');
    }
}
