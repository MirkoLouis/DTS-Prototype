<?php

namespace App\Modules\Users;

use App\Common\Database\Database;
use App\Common\Middleware\CacheMiddleware;
use App\Modules\Auth\CredentialPolicy;
use App\Modules\Auth\PasswordReminderService;
use App\Modules\Integrity\DigitalSignatureService;

/**
 * Admin resets of a user's credentials: the sign-in password, the Security PIN, or both.
 *
 * Who may reset, and the admin's own Security PIN check, are the controller's job; this class only
 * changes the account.
 */
class UserCredentialService
{
    // What an admin can reset
    public const TARGET_PASSWORD = 'password';
    public const TARGET_PIN = 'pin';
    public const TARGET_BOTH = 'both';
    public const TARGETS = [self::TARGET_PASSWORD, self::TARGET_PIN, self::TARGET_BOTH];

    /**
     * Whether a submitted value names a valid reset target.
     */
    public static function isValidTarget(mixed $target): bool
    {
        return is_string($target) && in_array($target, self::TARGETS, true);
    }

    /**
     * bcrypt hash of the default password, for accounts that start with it.
     */
    public static function defaultPasswordHash(): string
    {
        return password_hash(CredentialPolicy::DEFAULT_PASSWORD, PASSWORD_BCRYPT);
    }

    /**
     * Reset the chosen credential(s) of an account.
     */
    public function reset(int $userId, string $target): void
    {
        // The sign-in password goes back to the default and the account is flagged for the reminder
        if ($target === self::TARGET_PASSWORD || $target === self::TARGET_BOTH) {
            $this->resetSignInPassword($userId);
        }

        // The Security PIN is cleared; the user creates a new one at their next page view
        if ($target === self::TARGET_PIN || $target === self::TARGET_BOTH) {
            $this->resetSecurityPin($userId);
        }
    }

    /**
     * Set the sign-in password to the default password and flag the account for the reminder.
     */
    private function resetSignInPassword(int $userId): void
    {
        Database::getInstance()->query('UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id', [
            ':password' => self::defaultPasswordHash(),
            ':id' => $userId,
        ]);
        PasswordReminderService::flag($userId);
    }

    /**
     * Close the account's key periods and clear its signing key.
     */
    private function resetSecurityPin(int $userId): void
    {
        $db = Database::getInstance();

        // Close every key period still open now, including seeded periods with a future end.
        // PHP time (Asia/Manila) matches the clock that writes log timestamps; SQL NOW() uses the server timezone.
        $now = date('Y-m-d H:i:s');
        $db->query("UPDATE user_public_key_histories SET deactivated_at = :now, updated_at = :now2 WHERE user_id = :id AND (deactivated_at IS NULL OR deactivated_at > :now3)", [':id' => $userId, ':now' => $now, ':now2' => $now, ':now3' => $now]);
        $db->query("UPDATE users SET public_key = NULL, private_key = NULL, security_key_set_at = NULL WHERE id = :id", [':id' => $userId]);

        // The key period is closed, so drop the memoized key histories
        DigitalSignatureService::clearKeyCache();

        // Clear the user's personal page cache so they see the setup modal immediately on their next request
        CacheMiddleware::forgetUserPages($userId);
    }
}
