<?php

namespace App\Core;

/**
 * Security PIN resolution and the encrypted browser-side PIN cache.
 *
 * The browser never stores the PIN. After the first correct PIN entry the server issues a
 * token: the PIN sealed with XSalsa20-Poly1305 (sodium secretbox) under a random key that
 * exists only in this user's PHP session. The token is useless after logout (key destroyed),
 * for another user (user ID is sealed inside), or after PIN_CACHE_DURATION seconds.
 */
class SecurityHelper
{
    // Prefix that distinguishes a cached PIN token from a typed PIN
    public const TOKEN_PREFIX = 'pt1.';

    // Session keys used by the PIN cache
    private const SESSION_KEY = 'pin_cache_key';
    public const SESSION_ISSUED = 'pin_token_issued';
    public const SESSION_CLEAR = 'pin_token_clear';

    /**
     * Turn the submitted `pin` field (a typed PIN or a cached token) into the real PIN.
     *
     * Returns null when nothing usable was submitted, or when a token is invalid or expired;
     * in that case the browser is told to discard its cached token.
     */
    public static function resolvePin(?string $submittedPin): ?string
    {
        // Nothing submitted, or the legacy sentinel from early session-caching builds
        if ($submittedPin === null || $submittedPin === '' || $submittedPin === 'CACHED_PIN') {
            return null;
        }

        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

        // A cached token: open it, or tell the browser to drop it
        if (str_starts_with($submittedPin, self::TOKEN_PREFIX)) {
            $pin = self::openToken($submittedPin, $userId);
            if ($pin === null) {
                $_SESSION[self::SESSION_CLEAR] = true;
            }
            return $pin;
        }

        // No logged-in user: nothing to verify or count; signing will refuse later
        if ($userId === 0) {
            return $submittedPin;
        }

        // While timed out, neither verify nor count the PIN; IntegrityManager::signAction() refuses it
        $throttleKey = AttemptThrottle::key('pin', (string) $userId);
        if (AttemptThrottle::secondsLocked($throttleKey, time()) > 0) {
            return $submittedPin;
        }

        // A typed PIN: count failures and issue a cache token only if the PIN unlocks the user's key.
        // This runs before the workflow transaction opens, so a rollback cannot erase the failure.
        $unlocks = self::pinUnlocksKey($userId, $submittedPin);
        if ($unlocks === true) {
            AttemptThrottle::clear($throttleKey);
            $_SESSION[self::SESSION_ISSUED] = self::issueToken($submittedPin, $userId);
        } elseif ($unlocks === false) {
            AttemptThrottle::recordFailure($throttleKey, time());
        }

        return $submittedPin;
    }

    /**
     * Check a typed PIN by trial-decrypting the user's stored private key.
     *
     * Returns null when the user has no private key (such users are not throttled).
     */
    private static function pinUnlocksKey(int $userId, string $pin): ?bool
    {
        $user = Database::getInstance()->query("SELECT private_key FROM users WHERE id = :id", ['id' => $userId])->fetch();
        if (!$user || !$user['private_key']) {
            return null;
        }

        // decryptPrivateKey throws on a wrong PIN
        try {
            IntegrityManager::decryptPrivateKey($user['private_key'], $pin);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Seal the PIN, the user ID and an absolute expiry into a token.
     */
    private static function issueToken(string $pin, int $userId): string
    {
        // Create the per-session encryption key on first use
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = base64_encode(sodium_crypto_secretbox_keygen());
        }
        $key = base64_decode($_SESSION[self::SESSION_KEY]);

        $payload = json_encode([
            'pin' => $pin,
            'uid' => $userId,
            'exp' => time() + Config::get('app', 'pin_cache_duration', 900)
        ]);

        // Encrypt with a fresh nonce and prepend it to the ciphertext
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = sodium_crypto_secretbox($payload, $nonce, $key);

        return self::TOKEN_PREFIX . sodium_bin2base64($nonce . $sealed, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /**
     * Decrypt and validate a token; returns the PIN or null.
     */
    private static function openToken(string $token, int $userId): ?string
    {
        // No session key means the session that issued the token is gone
        if (empty($_SESSION[self::SESSION_KEY]) || $userId === 0) {
            return null;
        }

        // Decode the token body without throwing on malformed input
        try {
            $raw = sodium_base642bin(substr($token, strlen(self::TOKEN_PREFIX)), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException $e) {
            return null;
        }
        if (strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        // Authenticated decryption fails on any tampering or a key from another session
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, base64_decode($_SESSION[self::SESSION_KEY]));
        if ($plain === false) {
            return null;
        }

        // Enforce the owner and the absolute lifetime
        $payload = json_decode($plain, true);
        if (!is_array($payload) || ($payload['uid'] ?? 0) !== $userId || ($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return (string) $payload['pin'];
    }
}
