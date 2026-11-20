<?php

namespace App\Modules\Auth;

/**
 * The one place sign-in passwords are hashed, verified and checked for rehash (Argon2id, PHP default cost).
 *
 * Older bcrypt hashes ($2y$) still verify and report needsRehash(), so they upgrade on the next sign-in.
 */
class PasswordHasher
{
    // Argon2id hash (PHP defaults) of a discarded random string; verified against for unknown usernames so the timing matches a real account
    public const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$N2RPU2xpSkd5d3JrR0pFaA$C8Oxv1toizg9HMVTQNgctKtXvDvRqUnZT0pRqFE28Sg';

    // bcrypt cost 10 hash of a discarded random string; stands in for the bcrypt half of a sign-in check (see verifyForSignIn)
    public const BCRYPT_DUMMY_HASH = '$2y$10$SHVyyqRw0KG5DoIdteHaHOm7jQZjnx9pkCxiABJxorpbZH2U0v9Ye';

    /**
     * Sign-in verification that does the same hashing work for every attempt: one Argon2id and one bcrypt (cost 10)
     * verification. The stored hash replaces the dummy of its own kind and the other kind verifies against its dummy,
     * so an unknown username, an Argon2id account and a not-yet-upgraded bcrypt account answer in the same time
     * during the bcrypt to Argon2id migration. Known limitation: a bcrypt hash with PHP's default cost 12 (passwords
     * changed before v2.8) stays about 180 ms slower until its next sign-in upgrades it.
     * Returns true only when the stored hash verifies; a null hash (unknown username) never does.
     */
    public static function verifyForSignIn(string $password, ?string $storedHash): bool
    {
        // A stored hash takes the place of the dummy of its own kind
        $isBcrypt = $storedHash !== null && str_starts_with($storedHash, '$2y$');
        $argon2idHash = ($storedHash !== null && !$isBcrypt) ? $storedHash : self::DUMMY_HASH;
        $bcryptHash = $isBcrypt ? $storedHash : self::BCRYPT_DUMMY_HASH;

        // Both verifications always run (no short-circuiting)
        $argon2idMatches = password_verify($password, $argon2idHash);
        $bcryptMatches = password_verify($password, $bcryptHash);

        // Only the stored hash counts; a dummy never grants access
        if ($storedHash === null) {
            return false;
        }

        return $isBcrypt ? $bcryptMatches : $argon2idMatches;
    }

    /**
     * Hash a password with Argon2id.
     */
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    /**
     * Whether a password matches a stored hash (bcrypt or Argon2id).
     */
    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Whether a stored hash is not an Argon2id hash with the current parameters.
     */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }
}
