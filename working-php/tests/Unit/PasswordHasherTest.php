<?php

namespace Tests\Unit;

use App\Modules\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * PasswordHasher is the one place passwords are hashed: Argon2id, with bcrypt hashes still verifiable.
 */
class PasswordHasherTest extends TestCase
{
    // New hashes use Argon2id
    public function test_hash_is_argon2id(): void
    {
        $this->assertSame('argon2id', password_get_info(PasswordHasher::hash('x'))['algoName']);
    }

    // A fresh hash verifies its password and does not need a rehash
    public function test_hash_verifies_and_needs_no_rehash(): void
    {
        $hash = PasswordHasher::hash('Fixture-Pass-2026');

        $this->assertTrue(PasswordHasher::verify('Fixture-Pass-2026', $hash));
        $this->assertFalse(PasswordHasher::verify('Fixture-Pass-2027', $hash));
        $this->assertFalse(PasswordHasher::needsRehash($hash));
    }

    // Bcrypt ignores everything after byte 72; Argon2id does not
    public function test_passwords_differing_after_byte_72_do_not_verify(): void
    {
        $first = str_repeat('a', 72) . 'b';
        $second = str_repeat('a', 72) . 'c';

        // Documents why Argon2id is used: bcrypt truncates at 72 bytes
        $this->assertTrue(password_verify($second, password_hash($first, PASSWORD_BCRYPT)));
        $this->assertFalse(PasswordHasher::verify($second, PasswordHasher::hash($first)));
    }

    // A multibyte password differing only in its last character does not verify
    public function test_multibyte_password_differing_in_last_character_does_not_verify(): void
    {
        $first = str_repeat('é', 39) . 'a';
        $second = str_repeat('é', 39) . 'b';

        $this->assertFalse(PasswordHasher::verify($second, PasswordHasher::hash($first)));
    }

    // An existing bcrypt hash still verifies and is flagged for rehash
    public function test_bcrypt_hash_verifies_and_needs_rehash(): void
    {
        $old = password_hash('Fixture-Pass-2026', PASSWORD_BCRYPT, ['cost' => 10]);

        $this->assertTrue(PasswordHasher::verify('Fixture-Pass-2026', $old));
        $this->assertTrue(PasswordHasher::needsRehash($old));
    }

    // Sign-in verification accepts the right password for an Argon2id and for a bcrypt hash
    public function test_verify_for_sign_in_accepts_argon2id_and_bcrypt_hashes(): void
    {
        $argon2id = PasswordHasher::hash('Fixture-Pass-2026');
        $bcrypt = password_hash('Fixture-Pass-2026', PASSWORD_BCRYPT, ['cost' => 10]);

        $this->assertTrue(PasswordHasher::verifyForSignIn('Fixture-Pass-2026', $argon2id));
        $this->assertTrue(PasswordHasher::verifyForSignIn('Fixture-Pass-2026', $bcrypt));
    }

    // Sign-in verification refuses a wrong password and a missing hash
    public function test_verify_for_sign_in_refuses_wrong_password_and_missing_hash(): void
    {
        $argon2id = PasswordHasher::hash('Fixture-Pass-2026');
        $bcrypt = password_hash('Fixture-Pass-2026', PASSWORD_BCRYPT, ['cost' => 10]);

        $this->assertFalse(PasswordHasher::verifyForSignIn('wrong', $argon2id));
        $this->assertFalse(PasswordHasher::verifyForSignIn('wrong', $bcrypt));
        $this->assertFalse(PasswordHasher::verifyForSignIn('Fixture-Pass-2026', null));
    }

    // The bcrypt dummy hash has the cost of the migrating accounts' hashes
    public function test_bcrypt_dummy_hash_is_cost_10_bcrypt(): void
    {
        $info = password_get_info(PasswordHasher::BCRYPT_DUMMY_HASH);

        $this->assertSame('bcrypt', $info['algoName']);
        $this->assertSame(10, $info['options']['cost']);
    }

    // The dummy hash costs the same as a real hash, so unknown-user sign-in takes the same time
    public function test_dummy_hash_has_the_same_parameters_as_real_hashes(): void
    {
        $this->assertFalse(PasswordHasher::needsRehash(PasswordHasher::DUMMY_HASH));
        $this->assertSame(
            password_get_info(PasswordHasher::hash('x'))['options'],
            password_get_info(PasswordHasher::DUMMY_HASH)['options']
        );
    }
}
