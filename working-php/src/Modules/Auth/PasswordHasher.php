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
