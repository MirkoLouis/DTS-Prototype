<?php

namespace App\Integrity;

use App\Core\AttemptThrottle;
use App\Common\Config\Config;
use App\Common\Database\Database;

/**
 * Non-repudiation (Ed25519 + Argon2id): signs log entries with the user's PIN-protected Ed25519 key
 * (unwrapped with an Argon2id-derived key) or the server key, and verifies signatures against the
 * key that was active when the entry was written.
 */
class DigitalSignature
{
    // Per-process cache of user_public_key_histories rows, keyed by user ID
    private static array $keyHistoryCache = [];

    /**
     * Sign a message with the server's Ed25519 key (used for system-initiated actions).
     *
     * @throws \Exception when SYSTEM_SIGNING_SECRET is missing or malformed
     */
    public static function signAsSystem(string $message): string
    {
        // Fail closed: an unsigned system entry would break the chain's non-repudiation guarantee
        $secret = base64_decode(Config::get('app', 'system_signing_secret', ''), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \Exception("System signing key is not configured. Run: php scripts/generate-keys.php --system");
        }

        return base64_encode(sodium_crypto_sign_detached($message, $secret));
    }

    /**
     * Decrypt a user's stored private key with their PIN.
     *
     * Stored format: base64(salt16 || iv16 || AES-256-CBC(secret key)), AES key = Argon2id(PIN, salt).
     *
     * @throws \Exception on a wrong PIN or malformed key
     */
    public static function decryptPrivateKey(string $storedPrivateKey, string $pin): string
    {
        $decoded = base64_decode($storedPrivateKey, true);

        // Only the salted Argon2id format is accepted; the legacy unsalted format was retired
        if ($decoded === false || strlen($decoded) !== 112) {
            throw new \Exception("Invalid private key format. Please reset your Security PIN.");
        }

        $salt = substr($decoded, 0, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $iv = substr($decoded, SODIUM_CRYPTO_PWHASH_SALTBYTES, 16);
        $encryptedPriv = substr($decoded, SODIUM_CRYPTO_PWHASH_SALTBYTES + 16);

        $key = sodium_crypto_pwhash(
            32,
            $pin,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );

        $decryptedPriv = openssl_decrypt($encryptedPriv, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        // A wrong PIN yields a padding failure or a key of the wrong length
        if ($decryptedPriv === false || strlen($decryptedPriv) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \Exception("Invalid Security PIN or corrupted key.");
        }

        return $decryptedPriv;
    }

    /**
     * Generate an Ed25519 signature for a log entry's signed message.
     *
     * Users sign with their PIN-protected key; system actions (userId null) sign with the server key.
     *
     * @throws \Exception
     */
    public static function signAction(?int $userId, ?string $pin, string $message): string
    {
        // System-initiated actions are signed with the server key
        if ($userId === null) {
            return self::signAsSystem($message);
        }

        // Every user-initiated action requires the user's PIN
        if (empty($pin)) {
            throw new \Exception("Action Denied: Security PIN is required to digitally sign this action.");
        }

        // Refuse while the user is timed out for wrong PINs (failures are recorded in SecurityPinCache::resolvePin)
        if (AttemptThrottle::secondsLocked(AttemptThrottle::key('pin', (string) $userId), time()) > 0) {
            throw new \Exception(AttemptThrottle::MESSAGE);
        }

        $db = Database::getInstance();
        $user = $db->query("SELECT private_key FROM users WHERE id = :id", ['id' => $userId])->fetch();

        if (!$user || !$user['private_key']) {
            throw new \Exception("User does not have a digital signature set up.");
        }

        // Produce a detached signature over the full signed message
        $secretKey = self::decryptPrivateKey($user['private_key'], $pin);
        return base64_encode(sodium_crypto_sign_detached($message, $secretKey));
    }

    /**
     * Empty the per-process key-history cache.
     *
     * Call after any write to user_public_key_histories, and at the start of every audit, so a
     * long-lived process never verifies against a stale key list.
     */
    public static function clearKeyCache(): void
    {
        // Drop every memoized key history
        self::$keyHistoryCache = [];
    }

    /**
     * Resolve the public key a user had active at a given log timestamp.
     *
     * When key periods overlap, the most recently activated key wins.
     */
    public static function publicKeyAt(int $userId, string $createdAt): ?string
    {
        $db = Database::getInstance();

        // Load the user's key history once per process
        if (!array_key_exists($userId, self::$keyHistoryCache)) {
            self::$keyHistoryCache[$userId] = $db->query(
                "SELECT public_key, activated_at, deactivated_at FROM user_public_key_histories WHERE user_id = :uid ORDER BY activated_at DESC, id DESC",
                ['uid' => $userId]
            )->fetchAll();
        }

        // Pick the newest key whose activation period contains the log timestamp
        $time = strtotime($createdAt);
        foreach (self::$keyHistoryCache[$userId] as $history) {
            $activatedAt = strtotime($history['activated_at']);
            $deactivatedAt = $history['deactivated_at'] ? strtotime($history['deactivated_at']) : null;
            if ($activatedAt <= $time && ($deactivatedAt === null || $deactivatedAt >= $time)) {
                return $history['public_key'];
            }
        }

        return null;
    }

    /**
     * Verify a stored signature against the signer's key (server key when userId is null).
     *
     * @return array{valid: bool, key_source: string}
     */
    public static function verifySignature(?int $userId, string $createdAt, string $message, ?string $signature): array
    {
        // An entry without a signature is never valid in format v2
        $rawSignature = base64_decode((string) $signature, true);
        if ($rawSignature === false || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return ['valid' => false, 'key_source' => 'missing or malformed signature'];
        }

        // Choose the system key or the user's key active at that time
        if ($userId === null) {
            $publicKey = Config::get('app', 'system_signing_public', '');
            $keySource = 'system key';
        } else {
            $publicKey = self::publicKeyAt($userId, $createdAt);
            $keySource = "user {$userId} key active at {$createdAt}";
        }

        $rawPublicKey = base64_decode((string) $publicKey, true);
        if ($rawPublicKey === false || strlen($rawPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return ['valid' => false, 'key_source' => "no valid public key ({$keySource})"];
        }

        return [
            'valid' => sodium_crypto_sign_verify_detached($rawSignature, $message, $rawPublicKey),
            'key_source' => $keySource
        ];
    }
}
