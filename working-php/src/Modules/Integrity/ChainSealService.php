<?php

namespace App\Modules\Integrity;

use App\Common\Config\Config;
use App\Common\Database\Database;
use App\Common\Security\IntegrityKey;
use App\Modules\System\SystemAuditService;

/**
 * Part of the Trust Builder: the keyed seals (HMAC-SHA256 under APP_INTEGRITY_KEY) that close what the hash chain
 * alone leaves open.
 *
 * Entry seal  = HMAC(key, canonical JSON of [block hash, SHA-256 of the stored document_snapshot text]), stored in
 *               document_logs.seal. Without the key nobody can write a log entry whose seal verifies, even with a
 *               valid block hash, state hash and signature, and nobody can edit the snapshot recovery restores from.
 * Chain seal  = HMAC(key, canonical JSON of the document's workflow fields plus the head of its chain),
 *               stored in documents.chain_seal. The state hash does not cover status, current step,
 *               current holder, decline reason, released-by or the release date; the chain seal does, and it also binds
 *               the newest entry and the number of entries, so a deleted or appended entry is noticed.
 *               The input is versioned: v2 starts with the marker "chain-seal-v2" and adds released_at; v1 (versions 2.7.12
 *               to 2.7.14: no marker, no released_at) is kept only so scripts/seal-chains.php --upgrade-chain-seals can
 *               recognize an untampered old seal and re-seal it. Once the upgrade is recorded in the system audit log,
 *               v1 seals are no longer accepted anywhere else.
 *
 * Neither seal feeds into the block hash, the state hash or the signature: log format v2 is unchanged.
 * Like the system audit log this is tamper evidence: someone who holds the key and the database can re-seal.
 */
class ChainSealService
{
    // JSON flags for the sealed payloads, the same as every other hashed payload
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    // Documents sealed per transaction by the bulk sealer
    private const BATCH_SIZE = 500;

    // System audit log event that anchors "this database has been sealed" outside the database
    public const SEALED_EVENT = 'database_sealed';

    // System audit log event that anchors "every chain seal of this database is on version 2" outside the database
    public const UPGRADED_EVENT = 'chain_seals_upgraded';

    // upgradeState(): the upgrade is not recorded, is recorded, or the audit log does not verify so the answer cannot be trusted
    public const UPGRADE_PENDING = 'pending';
    public const UPGRADE_RECORDED = 'recorded';
    public const UPGRADE_LOG_FAILED = 'log_failed';

    // Why the upgrade left a document untouched
    public const REASON_NO_SEAL = 'no chain seal';
    public const REASON_NEITHER_VERSION = 'chain seal verifies under neither version';
    public const REASON_V1_AFTER_RECORD = 'v1 seal after the upgrade was recorded: investigate';
    public const REASON_CONCURRENT = 'changed concurrently, run again';

    // First element of the v2 chain seal input
    public const CHAIN_SEAL_V2_MARKER = 'chain-seal-v2';

    // Result of sealStatus(): current (v2), valid only on the old version (v1, upgrade not recorded yet) or does not verify
    public const SEAL_CURRENT = 'current';
    public const SEAL_OLD_VERSION = 'old_version';
    public const SEAL_OLD_VERSION_LOG_FAILED = 'old_version_log_failed';
    public const SEAL_MISMATCH = 'mismatch';

    /**
     * The entry seal: block hash and the snapshot text exactly as stored (null when the entry has no snapshot).
     *
     * The one function that defines the entry-seal input; every writer and verifier goes through it.
     */
    public static function entrySeal(string $blockHash, ?string $snapshotText): string
    {
        $snapshotDigest = $snapshotText === null ? null : hash('sha256', $snapshotText);

        return IntegrityKey::hmac(json_encode([$blockHash, $snapshotDigest], self::JSON_FLAGS));
    }

    /**
     * Whether a log row (with its hash and document_snapshot columns) carries a seal that verifies.
     */
    public static function entrySealIsValid(array $log): bool
    {
        // A caller that did not select the snapshot would verify the wrong input
        if (!array_key_exists('document_snapshot', $log)) {
            throw new \LogicException('The log row must include document_snapshot to verify its entry seal.');
        }

        // An unsealed entry (NULL or empty) never verifies
        $storedSeal = (string) ($log['seal'] ?? '');
        if ($storedSeal === '') {
            return false;
        }

        return hash_equals(self::entrySeal((string) $log['hash'], $log['document_snapshot'] === null ? null : (string) $log['document_snapshot']), $storedSeal);
    }

    /**
     * The v2 chain seal input (the current one): the marker, the workflow fields, the release date and the chain head.
     *
     * Order: "chain-seal-v2", document id, status, current step, current department id, decline reason, released-by user id,
     * released_at, newest log id, newest block hash, log count. released_at is the text the database returns, or null.
     * Integers are integers, text is text, NULL stays null (so NULL and an empty string differ), a missing status is an
     * empty string, a document without logs has null for the newest id and hash and 0 for the count.
     */
    public static function chainSealInput(array $document, ?array $newestLog, int $logCount): array
    {
        return [
            self::CHAIN_SEAL_V2_MARKER,
            ...self::workflowFields($document),
            self::nullableString($document['released_at'] ?? null),
            ...self::headValues($newestLog, $logCount),
        ];
    }

    /**
     * The v1 chain seal input (versions 2.7.12 to 2.7.14), kept only to recognize and upgrade old seals; nothing writes it.
     *
     * Order: document id, status, current step, current department id, decline reason, released-by user id,
     * newest log id, newest block hash, log count. No marker and no released_at.
     */
    public static function chainSealInputV1(array $document, ?array $newestLog, int $logCount): array
    {
        return [...self::workflowFields($document), ...self::headValues($newestLog, $logCount)];
    }

    /**
     * The v2 chain seal of a document, given its newest log row (id and hash) and its log count.
     */
    public static function chainSeal(array $document, ?array $newestLog, int $logCount): string
    {
        return IntegrityKey::hmac(json_encode(self::chainSealInput($document, $newestLog, $logCount), self::JSON_FLAGS));
    }

    /**
     * The v1 chain seal of a document (see chainSealInputV1).
     */
    public static function chainSealV1(array $document, ?array $newestLog, int $logCount): string
    {
        return IntegrityKey::hmac(json_encode(self::chainSealInputV1($document, $newestLog, $logCount), self::JSON_FLAGS));
    }

    /**
     * Whether a document row carries a chain seal that matches its current fields and the head of its chain in the database.
     */
    public static function documentSealIsValid(array $document): bool
    {
        [$newestLog, $logCount] = self::chainHead((int) $document['id']);

        return self::sealMatches($document, $newestLog, $logCount);
    }

    /**
     * Whether a document's stored chain seal equals the v1 seal recomputed from the given head (an untampered old seal).
     */
    public static function sealMatchesV1(array $document, ?array $newestLog, int $logCount): bool
    {
        $storedSeal = (string) ($document['chain_seal'] ?? '');
        if ($newestLog === null || $storedSeal === '') {
            return false;
        }

        return hash_equals(self::chainSealV1($document, $newestLog, $logCount), $storedSeal);
    }

    /**
     * The state of a document's chain seal: current (v2), old version (v1 that still verifies while the upgrade is not recorded),
     * old version with an audit log that does not verify, or mismatch.
     *
     * A v1 seal counts as "old version" only until the upgrade is recorded in the system audit log; after that it is a mismatch,
     * because otherwise someone could edit released_at and leave the old seal in place. When the audit log itself does not
     * verify the answer is unknown: nothing is blessed and nothing is frozen (SEAL_OLD_VERSION_LOG_FAILED). $upgradeState
     * (an UPGRADE_* value) lets a bulk caller look the audit log up once; null looks it up here, only when a v1 seal needs it.
     */
    public static function sealStatus(array $document, ?array $newestLog, int $logCount, ?string $upgradeState = null): string
    {
        if (self::sealMatches($document, $newestLog, $logCount)) {
            return self::SEAL_CURRENT;
        }
        if (!self::sealMatchesV1($document, $newestLog, $logCount)) {
            return self::SEAL_MISMATCH;
        }

        return match ($upgradeState ?? self::upgradeState()) {
            self::UPGRADE_PENDING => self::SEAL_OLD_VERSION,
            self::UPGRADE_LOG_FAILED => self::SEAL_OLD_VERSION_LOG_FAILED,
            default => self::SEAL_MISMATCH,
        };
    }

    /**
     * Whether a document's stored chain seal equals the v2 seal recomputed from the given head (a v1 seal never matches).
     *
     * A document without logs is valid only while it has no chain seal.
     */
    public static function sealMatches(array $document, ?array $newestLog, int $logCount): bool
    {
        $storedSeal = (string) ($document['chain_seal'] ?? '');
        if ($newestLog === null) {
            return $storedSeal === '';
        }
        if ($storedSeal === '') {
            return false;
        }

        return hash_equals(self::chainSeal($document, $newestLog, $logCount), $storedSeal);
    }

    /**
     * Write the entry seal of a log row that was just inserted (inside the caller's transaction).
     *
     * The snapshot is read back, because the JSON column stores its own canonical text and the seal covers the text as stored.
     */
    public static function sealInsertedEntry(int $logId): void
    {
        $db = Database::getInstance();
        $log = $db->query("SELECT hash, document_snapshot FROM document_logs WHERE id = :id", [':id' => $logId])->fetch();
        $db->query("UPDATE document_logs SET seal = :seal WHERE id = :id", [
            ':seal' => self::entrySeal((string) $log['hash'], $log['document_snapshot'] === null ? null : (string) $log['document_snapshot']),
            ':id' => $logId,
        ]);
    }

    /**
     * Recompute and store a document's chain seal from its current row and chain head.
     *
     * Runs in the caller's transaction, after the log insert. Touches neither version nor updated_at.
     * The chain head is read without FOR UPDATE on purpose: every caller has just changed the document row with an
     * UPDATE guarded by the version check (or inserted it), which holds the row's InnoDB lock until commit, so no other
     * writer can append to this document's chain between that UPDATE and this refresh.
     */
    public static function refresh(int $documentId): void
    {
        $db = Database::getInstance();
        $document = $db->query("SELECT id, status, current_step, current_department_id, decline_reason, released_by_user_id, released_at FROM documents WHERE id = :id", [':id' => $documentId])->fetch();
        if (!$document) {
            throw new \RuntimeException("Cannot seal document {$documentId}: it does not exist.");
        }

        [$newestLog, $logCount] = self::chainHead($documentId);
        $db->query("UPDATE documents SET chain_seal = :seal WHERE id = :id", [
            ':seal' => self::chainSeal($document, $newestLog, $logCount),
            ':id' => $documentId,
        ]);
    }

    /**
     * The newest log row (id, hash) of a document and the number of its log rows.
     *
     * @return array{0: ?array, 1: int}
     */
    public static function chainHead(int $documentId): array
    {
        $db = Database::getInstance();
        $newestLog = $db->query("SELECT id, hash FROM document_logs WHERE document_id = :id ORDER BY id DESC LIMIT 1", [':id' => $documentId])->fetch() ?: null;
        $logCount = (int) $db->query("SELECT COUNT(*) AS c FROM document_logs WHERE document_id = :id", [':id' => $documentId])->fetch()['c'];

        return [$newestLog, $logCount];
    }

    /**
     * Whether this database has been sealed, decided OUTSIDE the database: the first successful sealing records a
     * `database_sealed` event with the database name in the system audit log (the HMAC-chained file).
     *
     * A log that fails verification counts as sealed (fail closed): the answer cannot be trusted, and "sealed" is the safe one.
     * Tamper evidence only: someone who can also delete or replace the audit log file could pass the database off as never sealed.
     */
    public static function databaseIsSealed(): bool
    {
        $answer = SystemAuditService::lookup(self::SEALED_EVENT, ['database' => (string) Config::get('database', 'dbname', '')]);

        return !$answer['verified'] || $answer['found'];
    }

    /**
     * Whether every chain seal of this database is recorded as being on version 2, decided OUTSIDE the database: the upgrade
     * (scripts/seal-chains.php --upgrade-chain-seals) and the first sealing by this version record a `chain_seals_upgraded`
     * event with the database name in the system audit log.
     *
     * A log that fails verification counts as upgraded here (fail closed): nothing treats v1 seals as acceptable then.
     * Tamper evidence only, like databaseIsSealed(): someone who can delete the audit log file can reopen the v1 window.
     */
    public static function chainSealsUpgraded(): bool
    {
        return self::upgradeState() !== self::UPGRADE_PENDING;
    }

    /**
     * The upgrade record of this database: UPGRADE_RECORDED, UPGRADE_PENDING, or UPGRADE_LOG_FAILED when the system audit
     * log does not verify (then neither answer can be trusted).
     */
    public static function upgradeState(): string
    {
        $answer = SystemAuditService::lookup(self::UPGRADED_EVENT, ['database' => (string) Config::get('database', 'dbname', '')]);
        if (!$answer['verified']) {
            return self::UPGRADE_LOG_FAILED;
        }

        return $answer['found'] ? self::UPGRADE_RECORDED : self::UPGRADE_PENDING;
    }

    /**
     * The exception for a seal status that must be refused without freezing, or null for any other status.
     *
     * Shared by the pre-action check and the recovery path: a chain seal on the old version (upgrade not run yet), or a
     * chain seal that would be on the old version but whose audit log does not verify.
     */
    public static function refusalForSealStatus(string $sealStatus): ?DocumentNotSealedException
    {
        return match ($sealStatus) {
            self::SEAL_OLD_VERSION => new ChainSealsNeedUpgradeException(),
            self::SEAL_OLD_VERSION_LOG_FAILED => new AuditLogDoesNotVerifyException(),
            default => null,
        };
    }

    /**
     * The exception that refuses an action on a document that is not tampered but cannot be verified yet, or null when it can.
     *
     * Callers catch DocumentNotSealedException and freeze nothing: no chain seal yet, a chain seal on the old version, or an
     * old-version seal that cannot be judged because the audit log does not verify. Used by the recovery path; the
     * pre-action check applies the same rules after its other checks.
     */
    public static function sealingRefusal(array $document): ?DocumentNotSealedException
    {
        if (self::isNotSealedYet($document)) {
            return new DocumentNotSealedException();
        }

        // Only a document with logs and a stored seal can be on the old version
        [$newestLog, $logCount] = self::chainHead((int) $document['id']);
        if ($newestLog === null) {
            return null;
        }

        return self::refusalForSealStatus(self::sealStatus($document, $newestLog, $logCount));
    }

    /**
     * Whether a document is legitimately "not sealed yet": it has no chain seal and the database is not recorded as sealed.
     * On a sealed database a missing chain seal is tampering, not a benign state.
     */
    public static function isNotSealedYet(array $document): bool
    {
        return ((string) ($document['chain_seal'] ?? '')) === '' && !self::databaseIsSealed();
    }

    /**
     * Number of documents that have log entries but no chain seal (data from before the chain seal, or from an old backup).
     */
    public static function countUnsealedDocuments(): int
    {
        $row = Database::getInstance()->query(
            "SELECT COUNT(*) AS c FROM documents d WHERE (d.chain_seal IS NULL OR d.chain_seal = '') AND EXISTS (SELECT 1 FROM document_logs l WHERE l.document_id = d.id)"
        )->fetch();

        return (int) $row['c'];
    }

    /**
     * Seal existing data in batches, one transaction per batch (used by scripts/seal-chains.php and the seeders).
     *
     * A document is sealed only after its whole chain verifies (ChainVerificationService::verifyChain); a document
     * that does not verify is refused and listed with the reason. Default rules:
     *  - Only documents without a chain seal, or with entries without a seal, are considered; existing seals are never changed.
     *  - Once any document has a chain seal the database counts as sealed: a document with a missing seal is then
     *    NOT sealed but listed in "seal_missing" for review, unless $allowMissingOnSealedDatabase is true.
     * With $overwrite every seal of the selected documents is recomputed (still only for chains that verify).
     * $verify = false (the seeders, which have just written the data themselves) skips the verification.
     *
     * @param int[]|null $documentIds restrict to these documents, or null for all
     * @return array{documents_sealed: int, entries_sealed: int, refused: array<int, array{0: string, 1: string}>, seal_missing: string[], invalid_entry_seals: int, invalid_chain_seals: int, old_version_chain_seals: int}
     */
    public static function sealExisting(bool $overwrite = false, ?array $documentIds = null, bool $verify = true, bool $allowMissingOnSealedDatabase = false): array
    {
        // The key is required before anything is read
        IntegrityKey::require();
        DigitalSignatureService::clearKeyCache();

        $db = Database::getInstance();
        $connection = $db->getConnection();
        $context = [
            'overwrite' => $overwrite,
            'verify' => $verify,
            'allowMissing' => $allowMissingOnSealedDatabase,
            // Decided once, before this run seals anything, from the audit log (not from the data an attacker could edit)
            'databaseSealed' => self::databaseIsSealed(),
            'upgradeState' => self::upgradeState(),
        ];
        $stats = ['documents_sealed' => 0, 'entries_sealed' => 0, 'refused' => [], 'seal_missing' => [], 'invalid_entry_seals' => 0, 'invalid_chain_seals' => 0, 'old_version_chain_seals' => 0];

        // Walk the documents in id order, one batch at a time
        $lastId = 0;
        while (true) {
            $documents = self::documentBatch($lastId, $documentIds);
            if ($documents === []) {
                break;
            }
            $lastId = (int) end($documents)['id'];

            // One transaction per batch keeps the table lock time short and a failure local
            $connection->beginTransaction();
            try {
                self::sealBatch($documents, $context, $stats);
                $connection->commit();
            } catch (\Throwable $e) {
                $connection->rollBack();
                throw $e;
            }
        }

        // The first successful sealing of the database is recorded outside the database
        $fullRun = $documentIds === null;
        $successful = $stats['documents_sealed'] > 0 || ($fullRun && $stats['refused'] === [] && $stats['seal_missing'] === []);
        if (!$context['databaseSealed'] && $successful) {
            SystemAuditService::record(self::SEALED_EVENT, [
                'database' => (string) Config::get('database', 'dbname', ''),
                'documents_sealed' => $stats['documents_sealed'],
            ]);

        }

        // A full run that found no v1 seal leaves only v2 seals, so there is no v1 window to keep open. Data that still carries
        // v1 seals (a database sealed by 2.7.12 to 2.7.14 that lost its record) is never declared upgraded here: the upgrade command does that.
        if ($fullRun && $successful && $stats['old_version_chain_seals'] === 0 && $context['upgradeState'] === self::UPGRADE_PENDING) {
            SystemAuditService::record(self::UPGRADED_EVENT, [
                'database' => (string) Config::get('database', 'dbname', ''),
                'version' => 2,
                'source' => 'sealed with version 2',
            ]);
        }

        return $stats;
    }

    /**
     * The next batch of full document rows after $lastId.
     */
    private static function documentBatch(int $lastId, ?array $documentIds): array
    {
        $db = Database::getInstance();
        $sql = "SELECT * FROM documents WHERE id > ?";
        $params = [$lastId];

        // Restrict to the given documents when asked
        if ($documentIds !== null) {
            if ($documentIds === []) {
                return [];
            }
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($documentIds), '?')) . ')';
            array_push($params, ...array_map('intval', $documentIds));
        }

        return $db->query($sql . ' ORDER BY id ASC LIMIT ' . self::BATCH_SIZE, $params)->fetchAll();
    }

    /**
     * Seal one batch of documents and add to $stats.
     */
    private static function sealBatch(array $documents, array $context, array &$stats): void
    {
        $db = Database::getInstance();

        // Fetch every log row of the batch at once, grouped by document in id order
        $ids = array_column($documents, 'id');
        $logs = $db->query(
            'SELECT * FROM document_logs WHERE document_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY document_id ASC, id ASC',
            $ids
        )->fetchAll();
        $logsByDocument = [];
        foreach ($logs as $log) {
            $logsByDocument[$log['document_id']][] = $log;
        }

        foreach ($documents as $document) {
            self::sealDocument($document, $logsByDocument[$document['id']] ?? [], $context, $stats);
        }
    }

    /**
     * Seal one document's entries and chain seal according to the rules of sealExisting.
     */
    private static function sealDocument(array $document, array $documentLogs, array $context, array &$stats): void
    {
        $db = Database::getInstance();
        $overwrite = $context['overwrite'];

        // A document without any log has nothing to seal
        if ($documentLogs === []) {
            return;
        }

        if (!$overwrite) {
            // A fully sealed document is only checked and counted
            $hasChainSeal = ((string) ($document['chain_seal'] ?? '')) !== '';
            $missingEntrySeal = count(array_filter($documentLogs, fn ($log) => ((string) ($log['seal'] ?? '')) === '')) > 0;
            if ($hasChainSeal && !$missingEntrySeal) {
                self::reportSealedDocument($document, $documentLogs, $context, $stats);
                return;
            }

            // A seal that went missing is suspicious and is reported for review instead of sealed: always for a document that
            // already has a chain seal (an entry lost its seal), and for any document once the database is recorded as sealed
            if (($hasChainSeal || $context['databaseSealed']) && !$context['allowMissing']) {
                $stats['seal_missing'][] = $document['tracking_code'];
                return;
            }
        }

        // Seal only a chain that verifies completely (seals present must verify too, except when overwriting after a key rotation)
        if ($context['verify']) {
            $problem = ChainVerificationService::verifyChain($document, $documentLogs, !$overwrite);
            if ($problem !== null) {
                $stats['refused'][] = [$document['tracking_code'], $problem];
                return;
            }
        }

        // Entry seals: unsealed entries only, or every entry when overwriting
        foreach ($documentLogs as $log) {
            if (!$overwrite && ((string) ($log['seal'] ?? '')) !== '') {
                continue;
            }
            $db->query("UPDATE document_logs SET seal = :seal WHERE id = :id", [
                ':seal' => self::entrySeal((string) $log['hash'], $log['document_snapshot'] === null ? null : (string) $log['document_snapshot']),
                ':id' => $log['id'],
            ]);
            $stats['entries_sealed']++;
        }

        // Chain seal over the document's fields and the head of its chain
        $newest = end($documentLogs);
        $db->query("UPDATE documents SET chain_seal = :seal WHERE id = :id", [
            ':seal' => self::chainSeal($document, ['id' => $newest['id'], 'hash' => $newest['hash']], count($documentLogs)),
            ':id' => $document['id'],
        ]);
        $stats['documents_sealed']++;
    }

    /**
     * Count what is wrong inside a fully sealed document, without changing anything.
     */
    private static function reportSealedDocument(array $document, array $documentLogs, array $context, array &$stats): void
    {
        // Entry seals that do not verify
        foreach ($documentLogs as $log) {
            if (!self::entrySealIsValid($log)) {
                $stats['invalid_entry_seals']++;
            }
        }

        // The chain seal must still match the document and its chain
        $newest = ['id' => end($documentLogs)['id'], 'hash' => end($documentLogs)['hash']];
        $status = self::sealStatus($document, $newest, count($documentLogs), $context['upgradeState']);
        if ($status === self::SEAL_OLD_VERSION || $status === self::SEAL_OLD_VERSION_LOG_FAILED) {
            $stats['old_version_chain_seals']++;
        } elseif ($status === self::SEAL_MISMATCH) {
            $stats['invalid_chain_seals']++;
        }
    }

    /**
     * The workflow fields every chain seal version covers, normalized in this one place.
     */
    private static function workflowFields(array $document): array
    {
        return [
            (int) $document['id'],
            (string) ($document['status'] ?? ''),
            self::nullableInt($document['current_step'] ?? null),
            self::nullableInt($document['current_department_id'] ?? null),
            self::nullableString($document['decline_reason'] ?? null),
            self::nullableInt($document['released_by_user_id'] ?? null),
        ];
    }

    /**
     * The head of the chain as sealed: newest log id, newest block hash, log count (null, null and 0 without logs).
     */
    private static function headValues(?array $newestLog, int $logCount): array
    {
        return [
            $newestLog === null ? null : (int) $newestLog['id'],
            $newestLog === null ? null : (string) $newestLog['hash'],
            $logCount,
        ];
    }

    /**
     * Re-seal every document whose current chain seal verifies as v1 with the v2 seal (scripts/seal-chains.php --upgrade-chain-seals).
     *
     * A v1 seal that verifies proves the document was untampered under the old rules, so only then is the v2 seal written
     * (it also covers released_at, which v1 did not). A document whose seal verifies under neither version is listed and left
     * untouched; a document already on v2 is skipped, so a second run changes nothing. Entry seals are never touched.
     * One transaction per batch; each UPDATE is a compare-and-set on the old seal, so a concurrent change is reported, not overwritten.
     *
     * The upgrade runs ONCE per database. It refuses and changes nothing when the system audit log does not verify. Once
     * `chain_seals_upgraded` is recorded it upgrades nothing: a document whose seal verifies only under v1 is then listed
     * (REASON_V1_AFTER_RECORD), because a replayed v1 seal must not be blessed. $restoreMode (only RestoreBackupJob passes
     * it, the command line cannot) allows v1 seals after the record, for the data of one backup restore: restoring a backup
     * already means trusting its content. The record is written when the run starts from "not recorded" and nothing
     * conflicted, which is what ends the acceptance of v1 seals everywhere else.
     *
     * @return array{upgraded: int, already_current: int, untouched: array<int, array{0: string, 1: string}>, refused: ?string}
     */
    public static function upgradeChainSeals(bool $restoreMode = false): array
    {
        // The key is required before anything is read
        IntegrityKey::require();

        $connection = Database::getInstance()->getConnection();
        $stats = ['upgraded' => 0, 'already_current' => 0, 'untouched' => [], 'refused' => null];

        // An audit log that does not verify cannot say whether the upgrade happened: change nothing
        $state = self::upgradeState();
        if ($state === self::UPGRADE_LOG_FAILED) {
            $stats['refused'] = 'The system audit log does not verify, so it cannot be said whether the seals were already upgraded.';

            return $stats;
        }
        $allowOldVersion = $restoreMode || $state === self::UPGRADE_PENDING;

        // Walk the documents in id order, one transaction per batch
        $lastId = 0;
        while (true) {
            $documents = self::documentBatch($lastId, null);
            if ($documents === []) {
                break;
            }
            $lastId = (int) end($documents)['id'];

            $connection->beginTransaction();
            try {
                self::upgradeBatch($documents, $allowOldVersion, $stats);
                $connection->commit();
            } catch (\Throwable $e) {
                $connection->rollBack();
                throw $e;
            }
        }

        // Recorded only after every batch is done, and only when no v1 seal can be left (a conflict means one may be): an interrupted run leaves the rest "old version" (safe) and can be repeated
        $conflicts = count(array_filter($stats['untouched'], fn ($untouched) => $untouched[1] === self::REASON_CONCURRENT));
        if ($state === self::UPGRADE_PENDING && $conflicts === 0) {
            SystemAuditService::record(self::UPGRADED_EVENT, [
                'database' => (string) Config::get('database', 'dbname', ''),
                'version' => 2,
                'documents_upgraded' => $stats['upgraded'],
                'already_current' => $stats['already_current'],
                'left_untouched' => array_column($stats['untouched'], 0),
            ]);
        }

        return $stats;
    }

    /**
     * The log rows (document_id, id, hash) of a batch of documents, grouped by document in id order.
     */
    private static function logHeadsByDocument(array $documents): array
    {
        $ids = array_column($documents, 'id');
        $logs = Database::getInstance()->query(
            'SELECT document_id, id, hash FROM document_logs WHERE document_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY document_id ASC, id ASC',
            $ids
        )->fetchAll();
        $logsByDocument = [];
        foreach ($logs as $log) {
            $logsByDocument[$log['document_id']][] = $log;
        }

        return $logsByDocument;
    }

    /**
     * Upgrade one batch of documents and add to $stats (see upgradeChainSeals).
     */
    private static function upgradeBatch(array $documents, bool $allowOldVersion, array &$stats): void
    {
        $db = Database::getInstance();

        $logsByDocument = self::logHeadsByDocument($documents);

        foreach ($documents as $document) {
            $documentLogs = $logsByDocument[$document['id']] ?? [];

            // A document without logs has no seal to upgrade
            if ($documentLogs === []) {
                continue;
            }
            $newest = ['id' => end($documentLogs)['id'], 'hash' => end($documentLogs)['hash']];
            $logCount = count($documentLogs);

            if (self::sealMatches($document, $newest, $logCount)) {
                $stats['already_current']++;
            } elseif (self::sealMatchesV1($document, $newest, $logCount) && !$allowOldVersion) {
                $stats['untouched'][] = [$document['tracking_code'], self::REASON_V1_AFTER_RECORD];
            } elseif (self::sealMatchesV1($document, $newest, $logCount)) {
                // Compare-and-set on the seal that was verified: a concurrent change updates no row
                $updated = $db->query('UPDATE documents SET chain_seal = :seal WHERE id = :id AND chain_seal = :old', [
                    ':seal' => self::chainSeal($document, $newest, $logCount),
                    ':id' => $document['id'],
                    ':old' => $document['chain_seal'],
                ])->rowCount();
                if ($updated === 0) {
                    $stats['untouched'][] = [$document['tracking_code'], self::REASON_CONCURRENT];
                    continue;
                }
                $stats['upgraded']++;
            } else {
                $reason = ((string) ($document['chain_seal'] ?? '')) === '' ? self::REASON_NO_SEAL : self::REASON_NEITHER_VERSION;
                $stats['untouched'][] = [$document['tracking_code'], $reason];
            }
        }
    }

    /**
     * An integer, or null for a missing value.
     */
    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * A string, or null for a missing value.
     */
    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
