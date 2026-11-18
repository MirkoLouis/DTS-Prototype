<?php

namespace App\Modules\Integrity;

/**
 * Verification of log entries and whole chains (block hash, link, Ed25519 signature, snapshot against state hash,
 * live row against the newest state hash). Shared by the integrity check, recovery (unfreeze, auto-resolve),
 * the pre-action check and the sealing script, so the rules exist once.
 */
class ChainVerificationService
{
    // Action of the automatic freeze: it keeps the last valid state hash while its snapshot is the tampered row
    private const AUTO_FREEZE_ACTION = 'System Auto-Freeze';

    /**
     * Check one entry against the hash of the entry before it.
     *
     * @return array{hash: bool, link: bool, signature: bool} the block hash recomputed from the expected parent matches the stored one,
     *         the stored previous_hash is that parent, and the signature verifies for the signer's key valid at that time
     */
    public static function checkEntry(array $log, string $expectedPreviousHash): array
    {
        $userId = $log['user_id'] !== null ? (int) $log['user_id'] : null;
        $epoch = HashChainService::logTimestamp($log['created_at']);
        $remarks = (string) ($log['remarks'] ?? '');
        $stateHash = (string) $log['document_state_hash'];

        // The block hash is re-derived from the expected parent, not from the stored previous_hash
        $recalculated = HashChainService::chainHash((int) $log['document_id'], $userId, $log['action'], $epoch, $expectedPreviousHash, $stateHash, $remarks, (string) $log['signature']);

        // The signature covers the full signed message
        $message = HashChainService::signedMessage((int) $log['document_id'], $log['action'], $epoch, $expectedPreviousHash, $stateHash, $remarks);

        return [
            'hash' => hash_equals((string) $log['hash'], $recalculated),
            'link' => hash_equals($expectedPreviousHash, (string) $log['previous_hash']),
            'signature' => DigitalSignatureService::verifySignature($userId, $log['created_at'], $message, $log['signature'])['valid'],
        ];
    }

    /**
     * Whether a stored snapshot decodes and hashes to the recorded state hash (with the JSON spacing tolerance of liveStateMatches).
     */
    public static function snapshotMatchesStateHash(?string $snapshotText, ?string $recordedStateHash): bool
    {
        if ($snapshotText === null || $recordedStateHash === null) {
            return false;
        }
        $snapshot = json_decode($snapshotText, true);

        return is_array($snapshot) && self::liveStateMatches($snapshot, $recordedStateHash);
    }

    /**
     * Whether a document row (or snapshot) has the sealed state hash, tolerating harmless JSON spacing in finalized_route.
     */
    public static function liveStateMatches(array $document, string $sealedStateHash): bool
    {
        if (hash_equals($sealedStateHash, HashChainService::calculateStateHash($document))) {
            return true;
        }

        // Attempt a fallback JSON re-encoding to handle minor spacing differences between raw PDO and ORM outputs before declaring tampering
        if (!isset($document['finalized_route']) || !is_string($document['finalized_route'])) {
            return false;
        }
        $decoded = json_decode($document['finalized_route'], true);
        if ($decoded === null) {
            return false;
        }
        $alternative = $document;
        $alternative['finalized_route'] = json_encode($decoded);

        return hash_equals($sealedStateHash, HashChainService::calculateStateHash($alternative));
    }

    /**
     * Verify a whole chain from genesis: every block hash, link and signature, the snapshot of each entry against
     * its state hash, and the live row against the newest entry's state hash.
     *
     * @param array $document the full live row
     * @param array $logs every log row of the document in id order
     * @param bool $checkSeals also require every entry seal that is present to verify (not wanted after a key rotation)
     * @return string|null the first problem found, or null when the chain verifies completely
     */
    public static function verifyChain(array $document, array $logs, bool $checkSeals): ?string
    {
        $expectedPrevious = HashChainService::GENESIS_HASH;
        foreach ($logs as $log) {
            $checks = self::checkEntry($log, $expectedPrevious);
            if (!$checks['hash']) {
                return "block hash of entry {$log['id']} does not match its content";
            }
            if (!$checks['link']) {
                return "entry {$log['id']} is not linked to the entry before it";
            }
            if (!$checks['signature']) {
                return "entry {$log['id']} has an invalid signature";
            }

            // The automatic freeze's snapshot is the tampered row on purpose; a missing snapshot cannot be checked
            $isAutoFreeze = $log['user_id'] === null && $log['action'] === self::AUTO_FREEZE_ACTION;
            $snapshotCheckable = !$isAutoFreeze && $log['document_snapshot'] !== null;
            if ($snapshotCheckable && !self::snapshotMatchesStateHash($log['document_snapshot'], $log['document_state_hash'])) {
                return "the snapshot of entry {$log['id']} does not match its state hash";
            }
            if ($checkSeals && ((string) ($log['seal'] ?? '')) !== '' && !ChainSealService::entrySealIsValid($log)) {
                return "entry {$log['id']} has an invalid seal";
            }
            $expectedPrevious = $log['hash'];
        }

        // The live row must still match what the newest entry sealed
        $newest = end($logs);
        if ($newest !== false && !self::liveStateMatches($document, (string) $newest['document_state_hash'])) {
            return "the document's content does not match the state hash of its newest entry";
        }

        return null;
    }
}
