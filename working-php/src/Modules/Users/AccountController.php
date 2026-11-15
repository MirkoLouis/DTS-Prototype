<?php

namespace App\Modules\Users;

use App\Common\Database\Database;
use App\Common\Http\SessionService;
use App\Modules\Auth\AttemptThrottleService;
use App\Modules\Auth\CredentialPolicy;
use App\Modules\Auth\PasswordReminderService;
use App\Modules\Integrity\DigitalSignatureService;

/**
 * Self-service account pages for the signed-in user (any role). Currently: change the sign-in password.
 */
class AccountController
{
    // Throttle scope of wrong current-password attempts, keyed by user id (same limits as login and PIN)
    private const THROTTLE_SCOPE = 'account-password';

    // Throttle scope counting POSTs that got past the current-password check (a successful change or a refusal
    // for equalling the Security PIN): at most three per minute, the same rate as the PIN lockout
    private const OUTCOME_THROTTLE_SCOPE = 'account-password-outcome';

    /**
     * Show the change-password form.
     */
    public function showPasswordForm()
    {
        require BASE_PATH . '/src/Views/account/password.php';
    }

    /**
     * Change the signed-in user's sign-in password (POST /account/password).
     */
    public function changePassword()
    {
        $userId = (int) $_SESSION['user_id'];
        $throttleKey = AttemptThrottleService::key(self::THROTTLE_SCOPE, (string) $userId);

        // While timed out (wrong current passwords, or too many changes), refuse without checking anything
        $outcomeKey = AttemptThrottleService::key(self::OUTCOME_THROTTLE_SCOPE, (string) $userId);
        if (AttemptThrottleService::secondsLocked($throttleKey, time()) > 0 || AttemptThrottleService::secondsLocked($outcomeKey, time()) > 0) {
            $this->backWithError(AttemptThrottleService::MESSAGE);
        }

        // Fields sent as arrays (new_password[]=x) are never a password
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirmation = $_POST['new_password_confirmation'] ?? '';
        if (!is_string($current) || !is_string($new) || !is_string($confirmation)) {
            $this->backWithError('Fill in all three password fields.');
        }

        $user = Database::getInstance()->query('SELECT password, private_key FROM users WHERE id = :id', [':id' => $userId])->fetch();

        // A wrong current password counts as a failed attempt and shows the timeout message when it triggers the lock
        if (!$user || !password_verify($current, $user['password'])) {
            $lockedNow = AttemptThrottleService::recordFailure($throttleKey, time());
            $this->backWithError($lockedNow ? AttemptThrottleService::MESSAGE : 'Your current password is not correct.');
        }

        // The new password must satisfy every rule
        $refusal = $this->newPasswordRefusal($current, $new, $confirmation);
        if ($refusal !== null) {
            $this->backWithError($refusal);
        }

        // Once the current password has verified, a new password equal to the Security PIN is refused and any other
        // is accepted, which would let a stolen session guess the PIN; so both outcomes count toward a rate limit
        if (!empty($user['private_key']) && DigitalSignatureService::unlocksKey($user['private_key'], $new)) {
            AttemptThrottleService::recordFailure($outcomeKey, time());
            $this->backWithError('The new password must be different from your Security PIN.');
        }
        AttemptThrottleService::recordFailure($outcomeKey, time());

        // Store the new password and drop the reminder; earlier wrong current passwords are forgotten
        AttemptThrottleService::clear($throttleKey);
        Database::getInstance()->query('UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id', [
            ':password' => password_hash($new, PASSWORD_BCRYPT),
            ':id' => $userId,
        ]);
        PasswordReminderService::clear($userId);

        // Every session of the account ends, this one included: raising the version ends the others on their next
        // request, and this one is replaced by an anonymous session (new id) that carries the message to the login page
        UserCredentialService::raiseSessionVersion($userId);
        SessionService::endAuthenticatedSession('Your password has been changed. Please sign in again.');
        header('Location: /login');
        exit;
    }

    /**
     * The reason a new password is refused by the password rules, or null when it passes them.
     */
    private function newPasswordRefusal(string $current, string $new, string $confirmation): ?string
    {
        // Minimum length (any mix of letters, numbers and symbols)
        if (!CredentialPolicy::isLongEnough($new)) {
            return CredentialPolicy::tooShortMessage('password');
        }

        if ($new !== $confirmation) {
            return 'The new password and its confirmation do not match.';
        }

        // Keeping the old password, or choosing the default one, is no change
        if (hash_equals($current, $new)) {
            return 'The new password must be different from the current one.';
        }
        if (CredentialPolicy::isDefaultPassword($new)) {
            return 'The new password cannot be the default password.';
        }

        return null;
    }

    /**
     * Flash an error and return to the form.
     */
    private function backWithError(string $message): never
    {
        $_SESSION['error'] = $message;
        header('Location: ' . CredentialPolicy::CHANGE_PASSWORD_PATH);
        exit;
    }
}
