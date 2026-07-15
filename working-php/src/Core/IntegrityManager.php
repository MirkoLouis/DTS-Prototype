<?php

namespace App\Core;

class IntegrityManager
{
    /**
     * Calculate a SHA-256 hash representing the current state of a document.
     * This protects against tampering with document metadata (title, submitter, etc.).
     *
     * @param array $document An associative array containing document data
     * @return string
     */
    public static function calculateStateHash(array $document): string
    {
        // Use empty string as fallback for null values to mimic Laravel's (string) cast
        $trackingCode = $document['tracking_code'] ?? '';
        $title = $document['title'] ?? '';
        
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

        return hash('sha256', json_encode($stateData));
    }

    /**
     * Generate an Ed25519 signature for the given action and document state using Sodium.
     *
     * @param int|null $userId
     * @param string $pin
     * @param string $actionText
     * @param string $stateHash
     * @return string
     * @throws \Exception
     */
    public static function signAction(?int $userId, ?string $pin, string $actionText, string $stateHash): string
    {
        if (!$userId || empty($pin)) {
            return base64_encode("SYSTEM_SIG:{$actionText}|{$stateHash}");
        }

        $db = Database::getInstance();
        $user = $db->query("SELECT private_key FROM users WHERE id = :id", ['id' => $userId])->fetch();

        if (!$user || !$user['private_key']) {
            throw new \Exception("User does not have a digital signature set up.");
        }

        $decoded = base64_decode($user['private_key']);
        
        // Handle legacy format (64 bytes of AES ciphertext) vs new format (16 bytes salt + 16 bytes IV + 64 bytes ciphertext = 96 bytes)
        if (strlen($decoded) === 64) {
            $key = substr(hash('sha256', $pin), 0, 32);
            $iv = str_repeat('0', 16);
            $encryptedPriv = $decoded;
        } elseif (strlen($decoded) === 112) {
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
        } else {
            throw new \Exception("Invalid private key format.");
        }

        $decryptedPriv = openssl_decrypt($encryptedPriv, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($decryptedPriv === false || strlen($decryptedPriv) !== 64) {
            throw new \Exception("Invalid Security PIN or corrupted key.");
        }

        $signature = sodium_crypto_sign_detached($actionText . '|' . $stateHash, $decryptedPriv);
        return base64_encode($signature);
    }

    /**
     * Create a cryptographic log entry for a document action.
     * This calculates the state hash, signature, and block hash, chaining it to the previous log.
     *
     * @param int $documentId
     * @param int|null $userId
     * @param string $action
     * @param string $remarks
     * @param array $documentData The current state of the document (used for state hash)
     * @param string $pin The user's PIN for signature (can be empty for system actions)
     * @param string|null $overrideStateHash Optional state hash override for auto-freezing
     * @return int The ID of the newly created log entry
     */
    public static function createLog(int $documentId, ?int $userId, string $action, string $remarks, array $documentData, string $pin = '', ?string $overrideStateHash = null): int
    {
        $db = Database::getInstance();
        $createdAt = date('Y-m-d H:i:s');
        $timestampForHashing = date('c', strtotime($createdAt));

        // 1. Calculate the state hash based on current document data
        $documentStateHash = $overrideStateHash ?? self::calculateStateHash($documentData);

        // 2. Generate the signature bonded to the action and state hash
        $signature = self::signAction($userId, $pin, $action, $documentStateHash);

        // 3. Find the previous hash in the chain
        $stmt = $db->query(
            "SELECT hash FROM document_logs WHERE document_id = :document_id ORDER BY id DESC LIMIT 1",
            [':document_id' => $documentId]
        );
        $lastLog = $stmt->fetch();
        $previousHash = $lastLog ? $lastLog['hash'] : 'genesis_hash';

        // 4. Calculate the block hash
        $dataToHash = [
            $documentId,
            ($userId ?? ''),
            $action,
            $timestampForHashing,
            $previousHash,
            $documentStateHash,
            $signature
        ];

        $hash = hash('sha256', json_encode($dataToHash));

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
     * @param array $document
     * @return bool
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
        
        // Check for JSON spacing mismatch fallback (Eloquent vs PDO)
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
     * @param array $document
     * @param string $reason
     * @return void
     */
    public static function autoFreeze(array $document, string $reason): void
    {
        if (($document['status'] ?? '') === 'frozen') {
            return;
        }

        $db = Database::getInstance();
        
        // Update document status to frozen
        $db->query("UPDATE documents SET status = 'frozen', updated_at = NOW() WHERE id = :id", [
            ':id' => $document['id']
        ]);

        // Get the last valid document state hash so we propagate it, 
        // ensuring the scanner continues to flag the live state mismatch.
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
