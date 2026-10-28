<?php

namespace App\Core;

/**
 * Trust Builder: per-document SHA-256 hash chains sealed with Ed25519 signatures.
 *
 * Log format v2. Every writer (createLog, seeders) and every verifier (IntegrityCheckJob,
 * the hash debugger) builds its inputs through the helpers below, so the format is defined
 * in exactly one place:
 *   signed message = json([document_id, action, epoch, previous_hash, state_hash, remarks])
 *   chain hash     = sha256(json([document_id, user_id, action, epoch, previous_hash, state_hash, remarks, signature]))
 */
class IntegrityManager
{
    // Timezone in which created_at strings are written; hashing converts them to a Unix epoch
    public const APP_TIMEZONE = 'Asia/Manila';

    // Parent hash of the first entry in every document's chain
    public const GENESIS_HASH = 'genesis_hash';

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    // Per-process cache of user_public_key_histories rows, keyed by user ID
    private static array $keyHistoryCache = [];

    /**
     * Calculate a SHA-256 hash representing the current state of a document.
     *
     * Normalizes critical document fields into a consistent array structure to compute a tamper-evident SHA-256 state hash.
     */
    public static function calculateStateHash(array $document): string
    {
        // Normalize nulls to empty strings to maintain consistent hash outputs across DB dialects
        $trackingCode = $document['tracking_code'] ?? '';
        $title = $document['title'] ?? '';

        // Parse JSON guest info deterministically, falling back to empty fields if corrupted
        $guestInfo = ['name' => '', 'email' => '', 'phone' => ''];
        if (!empty($document['guest_info'])) {
            $parsedInfo = json_decode($document['guest_info'], true);
            if (is_array($parsedInfo)) {
                $guestInfo = array_merge($guestInfo, $parsedInfo);
            }
        }

        $district = $document['district'] ?? '';
        $department = $document['department'] ?? '';
        $purposeId = (string) ($document['purpose_id'] ?? '');

        $finalizedRoute = $document['finalized_route'] ?? '';
        if (is_array($finalizedRoute)) {
            $finalizedRoute = json_encode($finalizedRoute);
        }

        $stateData = [
            $trackingCode,
            $title,
            $guestInfo['name'],
            $guestInfo['email'],
            $guestInfo['phone'],
            $district,
            $department,
            $purposeId,
            $finalizedRoute
        ];
        return hash('sha256', json_encode($stateData, self::JSON_FLAGS));
    }

    /**
     * Convert a stored created_at string into the Unix epoch used for hashing.
     *
     * The timezone is explicit, so the result does not depend on the server's default timezone.
     */
    public static function logTimestamp(string $createdAt): int
    {
        return (new \DateTimeImmutable($createdAt, new \DateTimeZone(self::APP_TIMEZONE)))->getTimestamp();
    }

    /**
     * Build the exact byte string that is Ed25519-signed for a log entry.
     *
     * Binding the previous hash fixes the entry's position in its chain; binding remarks
     * makes the human explanation of the step non-repudiable as well.
     */
    public static function signedMessage(int $documentId, string $action, int $epoch, string $previousHash, string $stateHash, string $remarks): string
    {
        return json_encode([$documentId, $action, $epoch, $previousHash, $stateHash, $remarks], self::JSON_FLAGS);
    }

    /**
     * Ordered list of the values that make up a block's chain hash (also shown by the hash debugger).
     */
    public static function chainHashInput(int $documentId, ?int $userId, string $action, int $epoch, string $previousHash, string $stateHash, string $remarks, string $signature): array
    {
        return [$documentId, ($userId ?? ''), $action, $epoch, $previousHash, $stateHash, $remarks, $signature];
    }

    /**
     * Compute the SHA-256 chain hash of a block.
     */
    public static function chainHash(int $documentId, ?int $userId, string $action, int $epoch, string $previousHash, string $stateHash, string $remarks, string $signature): string
    {
        $input = self::chainHashInput($documentId, $userId, $action, $epoch, $previousHash, $stateHash, $remarks, $signature);
        return hash('sha256', json_encode($input, self::JSON_FLAGS));
    }

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

        // Refuse while the user is timed out for wrong PINs (failures are recorded in SecurityHelper::resolvePin)
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

    /**
     * Create a cryptographic log entry for a document action.
     *
     * Must run inside the caller's transaction after the document row's UPDATE, so the row lock
     * serializes appends to this document's chain.
     *
     * @param array $documentData The current state of the document (used for state hash)
     * @param string $pin The user's PIN (ignored for system actions)
     * @param string|null $overrideStateHash Optional state hash override for auto-freezing
     * @return int The ID of the newly created log entry
     */
    public static function createLog(int $documentId, ?int $userId, string $action, string $remarks, array $documentData, string $pin = '', ?string $overrideStateHash = null): int
    {
        $db = Database::getInstance();
        $createdAt = (new \DateTimeImmutable('now', new \DateTimeZone(self::APP_TIMEZONE)))->format('Y-m-d H:i:s');
        $epoch = self::logTimestamp($createdAt);

        // 1. Determine state hash; allow override for auto-freeze scenarios to preserve the last valid state
        $documentStateHash = $overrideStateHash ?? self::calculateStateHash($documentData);

        // 2. Fetch the parent block's hash first, because the signature binds it
        $lastLog = $db->query(
            "SELECT hash FROM document_logs WHERE document_id = :document_id ORDER BY id DESC LIMIT 1",
            [':document_id' => $documentId]
        )->fetch();
        $previousHash = $lastLog ? $lastLog['hash'] : self::GENESIS_HASH;

        // 3. Sign the full message: document, action, time, parent, state and remarks
        $message = self::signedMessage($documentId, $action, $epoch, $previousHash, $documentStateHash, $remarks);
        $signature = self::signAction($userId, $pin, $message);

        // 4. Seal the block with its chain hash
        $hash = self::chainHash($documentId, $userId, $action, $epoch, $previousHash, $documentStateHash, $remarks, $signature);

        // 5. Insert the log entry
        $db->query(
            "INSERT INTO document_logs
            (document_id, user_id, action, remarks, previous_hash, hash, signature, document_state_hash, document_snapshot, created_at)
            VALUES
            (:document_id, :user_id, :action, :remarks, :previous_hash, :hash, :signature, :document_state_hash, :document_snapshot, :created_at)",
            [
                ':document_id' => $documentId,
                ':user_id' => $userId,
                ':action' => $action,
                ':remarks' => $remarks,
                ':previous_hash' => $previousHash,
                ':hash' => $hash,
                ':signature' => $signature,
                ':document_state_hash' => $documentStateHash,
                ':document_snapshot' => json_encode($documentData),
                ':created_at' => $createdAt
            ]
        );

        return (int) $db->getConnection()->lastInsertId();
    }

    /**
     * Verify the current live state of a document against its last cryptographic log.
     *
     * Compares the live document row against the state sealed in its most recent cryptographic log
     * to detect unauthorized database tampering.
     */
    public static function verifyCurrentState(array $document): bool
    {
        $db = Database::getInstance();
        $stmt = $db->query(
            "SELECT document_state_hash FROM document_logs WHERE document_id = :document_id ORDER BY id DESC LIMIT 1",
            [':document_id' => $document['id']]
        );
        $lastLog = $stmt->fetch();

        // If no logs exist yet, it's a new document, nothing to verify against.
        if (!$lastLog) {
            return true;
        }

        $currentStateHash = self::calculateStateHash($document);

        // Attempt a fallback JSON re-encoding to handle minor spacing differences between raw PDO and ORM outputs before declaring tampering
        if ($currentStateHash !== $lastLog['document_state_hash']) {
            if (isset($document['finalized_route']) && is_string($document['finalized_route'])) {
                $decoded = json_decode($document['finalized_route'], true);
                if ($decoded !== null) {
                    $altDocument = $document;
                    $altDocument['finalized_route'] = json_encode($decoded);
                    if (self::calculateStateHash($altDocument) === $lastLog['document_state_hash']) {
                        return true;
                    }
                }
            }
            return false; // Real tampering detected
        }

        return true;
    }

    /**
     * Automatically freeze a document due to an integrity mismatch.
     *
     * Instantly halts workflow progression if a document fails the verifyCurrentState check,
     * sealing the tampered state for admin review.
     */
    public static function autoFreeze(array $document, string $reason): void
    {
        if (($document['status'] ?? '') === 'frozen') {
            return;
        }

        $db = Database::getInstance();

        // Freeze and bump the version so any in-flight transaction holding the old version aborts
        $db->query("UPDATE documents SET status = 'frozen', updated_at = NOW(), version = version + 1 WHERE id = :id", [
            ':id' => $document['id']
        ]);

        // Retrieve the last known good state hash to embed in the freeze log, ensuring the anomaly remains flagged
        $stmt = $db->query(
            "SELECT document_state_hash FROM document_logs WHERE document_id = :id ORDER BY id DESC LIMIT 1",
            [':id' => $document['id']]
        );
        $lastLog = $stmt->fetch();
        $validStateHash = $lastLog ? $lastLog['document_state_hash'] : self::calculateStateHash($document);

        // Create the system log entry for the auto-freeze
        self::createLog(
            $document['id'],
            null, // System-initiated
            'System Auto-Freeze',
            "Document automatically frozen by the Trust Builder system due to an integrity mismatch detected during {$reason}.",
            $document,
            '', // No PIN
            $validStateHash
        );
    }
}
