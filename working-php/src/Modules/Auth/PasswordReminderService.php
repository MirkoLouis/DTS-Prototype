<?php

namespace App\Modules\Auth;

use App\Common\Database\Database;
use App\Common\Middleware\CacheMiddleware;
use App\Modules\Notifications\NotificationService;

/**
 * The reminder to change a default or administrator-set sign-in password.
 *
 * Nothing is forced: while users.must_change_password is 1, each successful sign-in leaves exactly one
 * notification (with a link to the change-password page) and the account works as usual.
 */
class PasswordReminderService
{
    public const MESSAGE_DEFAULT_PASSWORD = 'You are using the default password.';
    public const MESSAGE_SET_BY_ADMINISTRATOR = 'Your password was set by an administrator.';

    /**
     * Set the reminder flag of an account.
     */
    public static function flag(int $userId): void
    {
        Database::getInstance()->query('UPDATE users SET must_change_password = 1 WHERE id = :id', [':id' => $userId]);
    }

    /**
     * Run after a successful sign-in: flag an account that signed in with the default password and,
     * while the account is flagged, replace its reminder with a fresh one.
     *
     * @param array  $user              the signed-in users row
     * @param string $submittedPassword the password typed at the login form
     */
    public static function afterSignIn(array $user, string $submittedPassword): void
    {
        $userId = (int) $user['id'];
        $usedDefaultPassword = CredentialPolicy::isDefaultPassword($submittedPassword);

        // Signing in with the default password flags the account, whoever set it
        if ($usedDefaultPassword) {
            self::flag($userId);
        }

        // Nothing to remind about
        if (!$usedDefaultPassword && empty($user['must_change_password'])) {
            return;
        }

        // Earlier reminders go first, so reminders never pile up
        $notifications = new NotificationService();
        $notifications->deleteForUserByLink($userId, CredentialPolicy::CHANGE_PASSWORD_PATH);
        $message = $usedDefaultPassword ? self::MESSAGE_DEFAULT_PASSWORD : self::MESSAGE_SET_BY_ADMINISTRATOR;
        $notifications->notifyUser($userId, $message, $message, 'warning', CredentialPolicy::CHANGE_PASSWORD_PATH, CredentialPolicy::CHANGE_PASSWORD_LABEL);

        // A cached page must not show the old notification list
        CacheMiddleware::forgetUserPages($userId);
    }

    /**
     * Clear the flag of an account and remove its reminders (after the user chose their own password).
     */
    public static function clear(int $userId): void
    {
        Database::getInstance()->query('UPDATE users SET must_change_password = 0 WHERE id = :id', [':id' => $userId]);
        (new NotificationService())->deleteForUserByLink($userId, CredentialPolicy::CHANGE_PASSWORD_PATH);

        // A cached page must not keep showing the removed reminder
        CacheMiddleware::forgetUserPages($userId);
    }
}
