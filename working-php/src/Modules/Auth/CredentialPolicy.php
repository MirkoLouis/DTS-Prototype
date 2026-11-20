<?php

namespace App\Modules\Auth;

/**
 * Rules for the two secrets of an account: the sign-in password and the Security PIN.
 *
 * Both share one minimum length, and the one default password is the single exemption from it.
 * Existing Security PINs shorter than the minimum keep working: nothing re-validates the length
 * of a PIN when it is used to sign.
 */
class CredentialPolicy
{
    // Minimum length (characters) of a new sign-in password and of a new Security PIN
    public const MIN_LENGTH = 10;

    // Sign-in password of a new or reset account; the only password allowed to be shorter than MIN_LENGTH
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
    public static function isLongEnough(string $secret): bool
    {
        return mb_strlen($secret) >= self::MIN_LENGTH;
    }

    /**
     * Error message for a secret that is too short, named by what it is ("password" or "Security PIN").
     */
    public static function tooShortMessage(string $label): string
    {
        return 'The new ' . $label . ' must be at least ' . self::MIN_LENGTH . ' characters.';
    }
}
