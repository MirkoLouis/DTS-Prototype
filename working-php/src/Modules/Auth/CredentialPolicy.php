<?php

namespace App\Modules\Auth;

/**
 * Rules for the two secrets of an account: the sign-in password and the Security PIN.
 *
 * A new password needs MIN_LENGTH_PASSWORD characters and a new PIN MIN_LENGTH_PIN, and a password
 * may not be on the common-password list. The one default password is the single exemption.
 * Existing shorter passwords and PINs keep working: nothing re-validates the length of a stored
 * password at sign-in or of a PIN when it is used to sign.
 */
class CredentialPolicy
{
    // Minimum length (characters) of a new sign-in password and of a new Security PIN
    public const MIN_LENGTH_PASSWORD = 15;
    public const MIN_LENGTH_PIN = 10;

    // Error shown for a new password found on the common-password list
    public const COMMON_PASSWORD_MESSAGE = 'This password is one of the most commonly used passwords. Choose a different one.';

    // Error shown when the common-password list cannot be read, so a new password cannot be checked
    public const LIST_UNAVAILABLE_MESSAGE = 'Your new password cannot be checked right now. Please try again later or contact the administrator.';

    // Sign-in password of a new or reset account; the only password allowed to be shorter than MIN_LENGTH_PASSWORD
    public const DEFAULT_PASSWORD = 'password';

    // Where a user changes their sign-in password, and the label of the reminder link that points there
    public const CHANGE_PASSWORD_PATH = '/account/password';
    public const CHANGE_PASSWORD_LABEL = 'Change password';

    // Sentence shown wherever a secret is chosen: a sign-in password and a Security PIN must differ
    public const SHARED_NOTE = 'Using the same passcode for sign-in and for signing is a security risk: anyone who learns one would have both.';

    // What each secret is, in the words used throughout the interface and documentation
    public const SIGN_IN_PASSWORD_DEFINITION = 'part of your sign-in credentials; it proves who you are when you log in.';
    public const SECURITY_PIN_DEFINITION = 'a second passcode that unlocks your signing key; you enter it to sign an action on a document.';

    /**
     * Whether a submitted password is the literal default password (timing-safe comparison).
     */
    public static function isDefaultPassword(string $password): bool
    {
        return hash_equals(self::DEFAULT_PASSWORD, $password);
    }

    /**
     * Whether a secret is long enough for a new password or PIN.
     */
    public static function isLongEnough(string $secret, int $minimum): bool
    {
        return mb_strlen($secret) >= $minimum;
    }

    /**
     * Error message for a secret that is too short, named by what it is ("password" or "Security PIN").
     */
    public static function tooShortMessage(string $label, int $minimum): string
    {
        return 'The new ' . $label . ' must be at least ' . $minimum . ' characters.';
    }

    /**
     * Whether a password (compared lower-cased) is a whole line of the common-password list.
     */
    public static function isCommonPassword(string $password): bool
    {
        return isset(self::commonPasswordSet()[mb_strtolower($password)]);
    }

    /**
     * The common-password list as a set, read once per process; a failed load is never cached.
     *
     * @return array<string, true>
     */
    private static function commonPasswordSet(): array
    {
        static $set = null;

        // Load on first use only; loadCommonPasswords throws before $set is assigned
        $set ??= self::loadCommonPasswords(__DIR__ . '/data/common-passwords.txt');

        return $set;
    }

    /**
     * Read a common-password list file into a set; throws if it is missing, unreadable or has no entries,
     * so a broken deployment never silently disables the blocklist.
     *
     * @return array<string, true>
     */
    public static function loadCommonPasswords(string $path): array
    {
        $lines = is_readable($path) ? file($path, FILE_IGNORE_NEW_LINES) : false;
        if ($lines === false) {
            throw new \RuntimeException('Common-password list is missing or unreadable: ' . $path);
        }

        $set = [];

        // Keep every entry line; skip header comments and blank lines
        foreach ($lines as $line) {
            $entry = rtrim($line, "\r");
            if ($entry === '' || str_starts_with($entry, '#')) {
                continue;
            }
            $set[$entry] = true;
        }

        // An empty list would let every password through
        if ($set === []) {
            throw new \RuntimeException('Common-password list has no entries: ' . $path);
        }

        return $set;
    }
}
