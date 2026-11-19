<?php

namespace App\Modules\Integrity;

use App\Common\Database\Database;
use App\Common\Security\IntegrityKey;
use App\Common\Security\IntegrityKeyMissingException;

/**
 * Pre-action integrity check and automatic freezing of tampered documents: before a mutating action,
 * the live document row is compared with the state sealed in its newest log entry, and a mismatch
 * freezes the document with a system-signed log entry.
 */
class PreActionIntegrityService
{
    /**
     * Verify the current live state of a document against its last cryptographic log.
     *
     * Compares the live document row against the state sealed in its most recent cryptographic log
     * to detect unauthorized database tampering.
     */
    public static function passes(array $document): bool
    {
        // Fetch the newest log entry: its sealed state hash, block hash and entry seal
        $db = Database::getInstance();
        $stmt = $db->query(
            "SELECT id, hash, seal, document_state_hash, document_snapshot FROM document_logs WHERE document_id = :document_id ORDER BY id DESC LIMIT 1",
            [':document_id' => $document['id']]
        );
        $lastLog = $stmt->fetch();
        $hasChainSeal = ((string) ($document['chain_seal'] ?? '')) !== '';

        // No logs yet: a new document passes, but only while it has no chain seal (a sealed document whose logs were all deleted fails)
        if (!$lastLog) {
            return !$hasChainSeal;
        }

        // Without the key no seal can be verified: refuse the action (and do not freeze anything: the document is not known to be tampered)
        if (!IntegrityKey::isConfigured()) {
            error_log('Pre-action integrity check refused document ' . $document['id'] . ': ' . IntegrityKey::missingMessage());
            throw new IntegrityKeyMissingException(IntegrityKey::USER_MESSAGE);
        }

        // Logs but no chain seal. On a database recorded as sealed that is tampering (a removed seal): a mismatch, like any other.
        // Otherwise the document is simply not sealed yet: refuse the action; the caller must not freeze the document.
        if (!$hasChainSeal && ChainSealService::databaseIsSealed()) {
            return false;
        }
        if (!$hasChainSeal) {
            error_log('Pre-action integrity check refused document ' . $document['id'] . ': it has not been sealed yet (log entries but no chain seal); run php scripts/seal-chains.php');
            throw new DocumentNotSealedException();
        }

        // The live fields covered by the state hash must match the newest entry
        if (!ChainVerificationService::liveStateMatches($document, (string) $lastLog['document_state_hash'])) {
            return false;
        }

        // The newest entry must carry a valid entry seal (an entry written without the key does not)
        if (!ChainSealService::entrySealIsValid($lastLog)) {
            return false;
        }

        // The chain seal must match the live workflow fields and the head of the chain
        $logCount = (int) $db->query("SELECT COUNT(*) AS c FROM document_logs WHERE document_id = :id", [':id' => $document['id']])->fetch()['c'];

        $status = ChainSealService::sealStatus($document, $lastLog, $logCount);
        $refusal = ChainSealService::refusalForSealStatus($status);
        if ($refusal !== null) {
            // An old-version seal (or one the audit log cannot judge): refuse without freezing, the same way as "not sealed yet" (every other check above passed)
            error_log('Pre-action integrity check refused document ' . $document['id'] . ': ' . $refusal->getMessage());
            throw $refusal;
        }

        return $status === ChainSealService::SEAL_CURRENT;
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

        // The freeze entry must be sealed, so refuse before changing anything when there is no key
        IntegrityKey::require();

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
        $validStateHash = $lastLog ? $lastLog['document_state_hash'] : HashChainService::calculateStateHash($document);

        // Create the system log entry for the automatic freezing
        HashChainService::createLog(
            $document['id'],
            null, // System-initiated
            'System Auto-Freeze',
            "Document automatically frozen by the pre-action integrity check due to an integrity mismatch detected during {$reason}.",
            $document,
            '', // No PIN
            $validStateHash,
            false // The chain seal is NOT refreshed: the mismatch stays flagged until an admin unfreezes or auto-resolves
        );
    }
}
