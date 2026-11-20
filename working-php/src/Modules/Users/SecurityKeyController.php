<?php

namespace App\Modules\Users;

use App\Common\Database\Database;
use App\Modules\Auth\AttemptThrottleService;
use App\Modules\Auth\CredentialPolicy;

class SecurityKeyController
{
    // Throttle scope of PINs refused for equalling the sign-in password, keyed by user id
    private const THROTTLE_SCOPE = 'pin-setup';

    public function store()
    {
        $pin = $_POST['pin'] ?? '';
        $pinConfirm = $_POST['pin_confirm'] ?? '';
        
        // Fields sent as arrays (pin[]=x) are never a PIN
        if (!is_string($pin) || !is_string($pinConfirm)) {
            $_SESSION['error'] = "Enter the Security PIN and its confirmation.";
            $_SESSION['pin_setup_error'] = $_SESSION['error'];
            $this->redirectBasedOnRole();
        }

        $db = Database::getInstance();
        $userId = $_SESSION['user_id'];

        // The rules for creating a PIN; the first broken one is shown
        $refusal = $this->refusal((int) $userId, $pin, $pinConfirm);
        if ($refusal !== null) {
            $_SESSION['error'] = $refusal;
            // The mandatory prompt shows the refusal itself (layouts/app.php), so it does not depend on a toast
            $_SESSION['pin_setup_error'] = $refusal;
            $this->redirectBasedOnRole();
        }

        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        // Encrypt the private key with the PIN using AES-256-CBC
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = sodium_crypto_pwhash(
            32, 
            $pin, 
            $salt, 
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, 
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, 
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
        $iv = random_bytes(16);
        $encryptedPriv = openssl_encrypt($secretKey, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        $pubB64 = base64_encode($publicKey);
        $privB64 = base64_encode($salt . $iv . $encryptedPriv);
        
        $now = date('Y-m-d H:i:s');

        $db->query("UPDATE users SET public_key = :pub, private_key = :priv, security_key_set_at = :now WHERE id = :id", [
            'pub' => $pubB64,
            'priv' => $privB64,
            'now' => $now,
            'id' => $userId
        ]);

        // Close every key period still open at this moment (including seeded periods with a future end),
        // so key periods never overlap and verification always resolves to exactly one key
        $db->query("UPDATE user_public_key_histories SET deactivated_at = :now, updated_at = :now2 WHERE user_id = :id AND (deactivated_at IS NULL OR deactivated_at > :now3)", [
            'now' => $now,
            'now2' => $now,
            'now3' => $now,
            'id' => $userId
        ]);

        $db->query("INSERT INTO user_public_key_histories (user_id, public_key, activated_at, created_at, updated_at) VALUES (:user_id, :pub, :now, :now2, :now3)", [
            'user_id' => $userId,
            'pub' => $pubB64,
            'now' => $now,
            'now2' => $now,
            'now3' => $now
        ]);

        // The new key period is written, so drop the memoized key histories
        \App\Modules\Integrity\DigitalSignatureService::clearKeyCache();

        // A new key invalidates any PIN-cache token issued for the old PIN
        unset($_SESSION['pin_cache_key']);
        $_SESSION[\App\Modules\Users\SecurityPinCacheService::SESSION_CLEAR] = true;

        // Clear the user's personal page cache. If we don't, CacheMiddleware might serve an old cached version
        // of the dashboard containing the setup modal, causing it to pop up infinitely.
        \App\Common\Middleware\CacheMiddleware::forgetUserPages((int) $userId);

        $_SESSION['success'] = "Digital Signature successfully generated and secured.";
        $this->redirectBasedOnRole();
    }

    /**
     * The reason a new PIN is refused, or null when it may be created.
     *
     * A key is only created for an account without one (an admin reset clears it), so this form can neither
     * replace a key nor be used for unlimited guessing of the sign-in password: every "equals the sign-in
     * password" refusal counts as a failed attempt, and a locked-out account is refused before any comparison.
     */
    private function refusal(int $userId, string $pin, string $pinConfirm): ?string
    {
        $account = Database::getInstance()->query("SELECT password, security_key_set_at FROM users WHERE id = :id", ['id' => $userId])->fetch();

        // An existing key is replaced only through an admin reset
        if (!$account || $account['security_key_set_at'] !== null) {
            return 'Your Security PIN is already set. Ask an administrator to reset it.';
        }

        // Refuse without comparing anything while the account is timed out
        $throttleKey = AttemptThrottleService::key(self::THROTTLE_SCOPE, (string) $userId);
        if (AttemptThrottleService::secondsLocked($throttleKey, time()) > 0) {
            return AttemptThrottleService::MESSAGE;
        }

        // A new PIN has the same minimum length as a new password (existing shorter PINs keep working)
        if (!CredentialPolicy::isLongEnough($pin)) {
            return CredentialPolicy::tooShortMessage('Security PIN');
        }

        if ($pin !== $pinConfirm) {
            return 'PIN and Confirm PIN do not match.';
        }

        // The PIN must differ from the sign-in password; a match counts as a failed attempt
        if (password_verify($pin, $account['password'])) {
            $lockedNow = AttemptThrottleService::recordFailure($throttleKey, time());
            return $lockedNow ? AttemptThrottleService::MESSAGE : 'The Security PIN must be different from your sign-in password.';
        }

        return null;
    }

    private function redirectBasedOnRole()
    {
        redirect_to_role_home($_SESSION['role'] ?? 'staff');
    }
}
