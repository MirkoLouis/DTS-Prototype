<?php

namespace App\Integrity;

use App\Core\Database;

/**
 * Pre-action integrity check and automatic freezing of tampered documents: before a mutating action,
 * the live document row is compared with the state sealed in its newest log entry, and a mismatch
 * freezes the document with a system-signed log entry.
 */
class PreActionIntegrityCheck
{
    /**
     * Verify the current live state of a document against its last cryptographic log.
     *
     * Compares the live document row against the state sealed in its most recent cryptographic log
     * to detect unauthorized database tampering.
     */
    public static function passes(array $document): bool
    {
        // Fetch the state hash sealed in this document's newest log entry
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

        $currentStateHash = HashChainMicroShardingBuilder::calculateStateHash($document);

        // Attempt a fallback JSON re-encoding to handle minor spacing differences between raw PDO and ORM outputs before declaring tampering
        if ($currentStateHash !== $lastLog['document_state_hash']) {
            if (isset($document['finalized_route']) && is_string($document['finalized_route'])) {
                $decoded = json_decode($document['finalized_route'], true);
                if ($decoded !== null) {
                    $altDocument = $document;
                    $altDocument['finalized_route'] = json_encode($decoded);
                    if (HashChainMicroShardingBuilder::calculateStateHash($altDocument) === $lastLog['document_state_hash']) {
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
     * Instantly halts workflow progression if a document fails the passes() check,
     * sealing the tampered state for admin review.
     */
    public static function freezeTampered(array $document, string $reason): void
    {
        // An already frozen document needs no second freeze entry
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
        $validStateHash = $lastLog ? $lastLog['document_state_hash'] : HashChainMicroShardingBuilder::calculateStateHash($document);

        // Create the system log entry for the automatic freezing
        HashChainMicroShardingBuilder::createLog(
            $document['id'],
            null, // System-initiated
            'System Auto-Freeze',
            "Document automatically frozen by the pre-action integrity check due to an integrity mismatch detected during {$reason}.",
            $document,
            '', // No PIN
            $validStateHash
        );
    }
}
