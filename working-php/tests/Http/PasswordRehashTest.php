<?php

namespace Tests\Http;

use App\Common\Database\Database;
use App\Modules\Auth\AuthController;
use App\Modules\Auth\PasswordHasher;
use Tests\Support\HttpClient;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Sign-in verifies through PasswordHasher and upgrades a legacy bcrypt hash to Argon2id on success.
 */
class PasswordRehashTest extends TestCase
{
    // The stored password hash of a username
    private function storedHash(string $username): string
    {
        return Database::getInstance()->query('SELECT password FROM users WHERE email = :e', [':e' => $username])->fetch()['password'];
    }

    // Store a hash directly in the database
    private function storeHash(string $username, string $hash): void
    {
        Database::getInstance()->query('UPDATE users SET password = :p WHERE email = :e', [':p' => $hash, ':e' => $username]);
    }

    // Post the sign-in form in a fresh browser; returns the client and the response
    private function signIn(string $username, string $password): array
    {
        $client = HttpClient::start();
        $client->get('/login');
        $response = $client->post('/login', ['email' => $username, 'password' => $password]);

        return [$client, $response];
    }

    // A bcrypt account signs in, its hash becomes Argon2id, and a second sign-in leaves it alone
    public function test_bcrypt_account_signs_in_and_is_upgraded_once(): void
    {
        $this->storeHash('cash.unit', TestDatabase::LEGACY_BCRYPT_HASH);

        [, $response] = $this->signIn('cash.unit', TestDatabase::PASSWORD);

        $this->assertSame('/tasks', $response['headers']['location'] ?? '');
        $upgraded = $this->storedHash('cash.unit');
        $this->assertSame('argon2id', password_get_info($upgraded)['algoName']);
        $this->assertTrue(password_verify(TestDatabase::PASSWORD, $upgraded));

        // Signing in again (fresh browser) does not rewrite the hash
        [, $second] = $this->signIn('cash.unit', TestDatabase::PASSWORD);
        $this->assertSame('/tasks', $second['headers']['location'] ?? '');
        $this->assertSame($upgraded, $this->storedHash('cash.unit'));
    }

    // A failed sign-in never rewrites a bcrypt hash
    public function test_failed_sign_in_leaves_a_bcrypt_hash_alone(): void
    {
        $this->storeHash('cash.unit', TestDatabase::LEGACY_BCRYPT_HASH);

        $this->signIn('cash.unit', 'not-the-password');

        $this->assertSame(TestDatabase::LEGACY_BCRYPT_HASH, $this->storedHash('cash.unit'));
    }

    // Argon2id has no 72-byte limit: a password differing only after byte 72 is refused
    public function test_long_password_differing_after_byte_72_is_refused(): void
    {
        $base = str_repeat('a', 72);
        $this->storeHash('cash.unit', PasswordHasher::hash($base . 'b'));

        [$client, $wrong] = $this->signIn('cash.unit', $base . 'c');
        $this->assertSame('/login', $wrong['headers']['location'] ?? '');
        $this->assertStringContainsString(AuthController::FAILED_SIGN_IN_MESSAGE, $client->get('/login')['body']);

        [, $right] = $this->signIn('cash.unit', $base . 'b');
        $this->assertSame('/tasks', $right['headers']['location'] ?? '');
    }

    // Every seeded fixture account holds an Argon2id hash
    public function test_fixture_accounts_use_argon2id(): void
    {
        $hashes = Database::getInstance()->query('SELECT email, password FROM users')->fetchAll();

        $this->assertNotEmpty($hashes);
        foreach ($hashes as $row) {
            $this->assertSame('argon2id', password_get_info($row['password'])['algoName'], $row['email']);
        }
    }
}
