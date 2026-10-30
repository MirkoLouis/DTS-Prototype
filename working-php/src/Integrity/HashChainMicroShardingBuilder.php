<?php

namespace App\Integrity;

use App\Common\Database\Database;

/**
 * SHA-256 hash-chain micro-sharding builder: each document keeps its own independent hash chain
 * (one micro-shard per document), and every block is sealed with an Ed25519 signature.
 *
 * Log format v2. Every writer (createLog, seeders) and every verifier (IntegrityCheckJob,
 * the hash debugger) builds its inputs through the helpers below, so the format is defined
 * in exactly one place:
 *   signed message = json([document_id, action, epoch, previous_hash, state_hash, remarks])
 *   chain hash     = sha256(json([document_id, user_id, action, epoch, previous_hash, state_hash, remarks, signature]))
 */
class HashChainMicroShardingBuilder
{
    // Timezone in which created_at strings are written; hashing converts them to a Unix epoch
    public const APP_TIMEZONE = 'Asia/Manila';

    // Parent hash of the first entry in every document's chain
    public const GENESIS_HASH = 'genesis_hash';

    // JSON flags for every hashed or signed payload (slashes and Unicode kept literal)
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

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

        // Read the routing fields, normalizing a missing purpose to an empty string
        $district = $document['district'] ?? '';
        $department = $document['department'] ?? '';
        $purposeId = (string) ($document['purpose_id'] ?? '');

        // Encode an already decoded finalized route back to its JSON string form
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
     * Create a cryptographic log entry for a document action.
     *
     * Must run inside the caller's transaction after the document row's UPDATE, so the row lock
     * serializes appends to this document's chain.
     *
     * @param array $documentData The current state of the document (used for state hash)
     * @param string $pin The user's PIN (ignored for system actions)
     * @param string|null $overrideStateHash Optional state hash override for automatic freezing
     * @return int The ID of the newly created log entry
     */
    public static function createLog(int $documentId, ?int $userId, string $action, string $remarks, array $documentData, string $pin = '', ?string $overrideStateHash = null): int
    {
        $db = Database::getInstance();
        $createdAt = (new \DateTimeImmutable('now', new \DateTimeZone(self::APP_TIMEZONE)))->format('Y-m-d H:i:s');
        $epoch = self::logTimestamp($createdAt);

        // 1. Determine state hash; allow override for automatic freezing scenarios to preserve the last valid state
        $documentStateHash = $overrideStateHash ?? self::calculateStateHash($documentData);

        // 2. Fetch the parent block's hash first, because the signature binds it
        $lastLog = $db->query(
            "SELECT hash FROM document_logs WHERE document_id = :document_id ORDER BY id DESC LIMIT 1",
            [':document_id' => $documentId]
        )->fetch();
        $previousHash = $lastLog ? $lastLog['hash'] : self::GENESIS_HASH;

        // 3. Sign the full message: document, action, time, parent, state and remarks
        $message = self::signedMessage($documentId, $action, $epoch, $previousHash, $documentStateHash, $remarks);
        $signature = DigitalSignature::signAction($userId, $pin, $message);

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
}
