<?php

namespace App\Modules\Integrity;

use App\Common\Config\Config;
use App\Common\Database\Database;
use App\Common\Security\IntegrityKey;
use App\Modules\System\SystemAuditService;

/**
 * Keyed seals (HMAC-SHA256 under APP_INTEGRITY_KEY) that close what the hash chain alone leaves open.
 *
 * Entry seal  = HMAC(key, canonical JSON of [block hash, SHA-256 of the stored document_snapshot text]), stored in
 *               document_logs.seal. Without the key nobody can write a log entry whose seal verifies, even with a
 *               valid block hash, state hash and signature, and nobody can edit the snapshot recovery restores from.
 * Chain seal  = HMAC(key, canonical JSON of the document's workflow fields plus the head of its chain),
 *               stored in documents.chain_seal. The state hash does not cover status, current step,
 *               current holder, decline reason or released-by; the chain seal does, and it also binds the
 *               newest entry and the number of entries, so a deleted or appended entry is noticed.
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
     * The values that make up a chain seal, with nulls and types normalized in this one place.
     *
     * Order: document id, status, current step, current department id, decline reason, released-by user id,
     * newest log id, newest block hash, log count. Integers are integers, text is text, NULL stays null
     * (so NULL and an empty string differ), a missing status is an empty string, a document without logs has
     * null for the newest id and hash and 0 for the count.
     */
    public static function chainSealInput(array $document, ?array $newestLog, int $logCount): array
    {
        return [
            (int) $document['id'],
            (string) ($document['status'] ?? ''),
            self::nullableInt($document['current_step'] ?? null),
            self::nullableInt($document['current_department_id'] ?? null),
            self::nullableString($document['decline_reason'] ?? null),
            self::nullableInt($document['released_by_user_id'] ?? null),
            $newestLog === null ? null : (int) $newestLog['id'],
            $newestLog === null ? null : (string) $newestLog['hash'],
            $logCount,
        ];
    }

    /**
     * The chain seal of a document, given its newest log row (id and hash) and its log count.
     */
    public static function chainSeal(array $document, ?array $newestLog, int $logCount): string
    {
        return IntegrityKey::hmac(json_encode(self::chainSealInput($document, $newestLog, $logCount), self::JSON_FLAGS));
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
     * Whether a document's stored chain seal equals the one recomputed from the given head.
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
        $document = $db->query("SELECT id, status, current_step, current_department_id, decline_reason, released_by_user_id FROM documents WHERE id = :id", [':id' => $documentId])->fetch();
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
     * @return array{documents_sealed: int, entries_sealed: int, refused: array<int, array{0: string, 1: string}>, seal_missing: string[], invalid_entry_seals: int, invalid_chain_seals: int}
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
        ];
        $stats = ['documents_sealed' => 0, 'entries_sealed' => 0, 'refused' => [], 'seal_missing' => [], 'invalid_entry_seals' => 0, 'invalid_chain_seals' => 0];

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
                self::reportSealedDocument($document, $documentLogs, $stats);
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
    private static function reportSealedDocument(array $document, array $documentLogs, array &$stats): void
    {
        // Entry seals that do not verify
        foreach ($documentLogs as $log) {
            if (!self::entrySealIsValid($log)) {
                $stats['invalid_entry_seals']++;
            }
        }

        // The chain seal must still match the document and its chain
        $newest = ['id' => end($documentLogs)['id'], 'hash' => end($documentLogs)['hash']];
        if (!self::sealMatches($document, $newest, count($documentLogs))) {
            $stats['invalid_chain_seals']++;
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
