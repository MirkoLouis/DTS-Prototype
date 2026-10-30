<?php

namespace Tests\Support;

use App\Modules\Integrity\HashChainService;
use PDO;
use RuntimeException;

/**
 * Direct-database tamper tactics of a privileged insider (raw PDO, no application code paths).
 *
 * Every public method has the signature (PDO $pdo, int $documentId): void and targets one
 * completed lifecycle document. The method names are the camelCase form of the matrix tactic names.
 */
final class Tamper
{
    // Action and remarks the forging tactics write into their fake log entry
    private const FORGED_ACTION = 'Document Released';
    private const FORGED_REMARKS = 'Title corrected per client request.';

    // ---------------------------------------------------------------
    // Live document row (documents table)
    // ---------------------------------------------------------------

    // Prefix the title, as an insider rewriting a document's subject would
    public static function editTitle(PDO $pdo, int $documentId): void
    {
        $pdo->prepare("UPDATE documents SET title = CONCAT('[TAMPERED] ', title) WHERE id = ?")->execute([$documentId]);
    }

    // Replace the submitter's email inside guest_info
    public static function editGuestEmail(PDO $pdo, int $documentId): void
    {
        $pdo->prepare("UPDATE documents SET guest_info = JSON_SET(guest_info, '$.email', 'attacker@example.com') WHERE id = ?")->execute([$documentId]);
    }

    // Remove the Personnel Unit step from the finalized route, as if it had never been required
    public static function editFinalizedRouteSkipDepartment(PDO $pdo, int $documentId): void
    {
        $route = json_decode((string) self::document($pdo, $documentId)['finalized_route'], true) ?: [];

        // Keep every step except the skipped department
        $kept = array_values(array_filter($route, fn ($step) => ($step['name'] ?? null) !== 'Personnel Unit'));
        if (count($kept) === count($route)) {
            throw new RuntimeException('Fixture route has no Personnel Unit step to skip.');
        }

        $pdo->prepare('UPDATE documents SET finalized_route = ? WHERE id = ?')->execute([json_encode($kept), $documentId]);
    }

    // Run the demo script scripts/corrupt.php against the test database, exactly as in the demo
    public static function corruptScript(PDO $pdo, int $documentId): void
    {
        $trackingCode = self::document($pdo, $documentId)['tracking_code'];

        // Subprocess with APP_ENV=testing so the script's Database::getInstance() reads .env.testing
        $env = array_merge(getenv(), ['APP_ENV' => 'testing']);
        $process = proc_open(
            [PHP_BINARY, BASE_PATH . '/scripts/corrupt.php', $trackingCode],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            BASE_PATH,
            $env
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start scripts/corrupt.php');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // The script must report success, otherwise the case would measure nothing
        if ($exitCode !== 0 || !str_contains((string) $stdout, 'DATABASE TAMPERING INJECTED')) {
            throw new RuntimeException("scripts/corrupt.php failed (exit {$exitCode}): {$stdout}{$stderr}");
        }
    }

    // Reopen the completed document by changing only its status (outside the state hash, M8)
    public static function editStatusOnly(PDO $pdo, int $documentId): void
    {
        $pdo->prepare("UPDATE documents SET status = 'processing' WHERE id = ?")->execute([$documentId]);
    }

    // Move the document to a different route step (outside the state hash, M8)
    public static function editCurrentStep(PDO $pdo, int $documentId): void
    {
        $current = (int) self::document($pdo, $documentId)['current_step'];
        $pdo->prepare('UPDATE documents SET current_step = ? WHERE id = ?')->execute([$current === 1 ? 2 : 1, $documentId]);
    }

    // ---------------------------------------------------------------
    // Existing log entries (document_logs table)
    // ---------------------------------------------------------------

    // Rewrite the remarks of a middle log entry
    public static function editLogRemarks(PDO $pdo, int $documentId): void
    {
        $log = self::middleLog($pdo, $documentId);
        $pdo->prepare('UPDATE document_logs SET remarks = ? WHERE id = ?')->execute(['Remarks rewritten by attacker.', $log['id']]);
    }

    // Change the recorded action of a middle log entry
    public static function editLogAction(PDO $pdo, int $documentId): void
    {
        $log = self::middleLog($pdo, $documentId);
        $pdo->prepare("UPDATE document_logs SET action = 'Document Declined' WHERE id = ?")->execute([$log['id']]);
    }

    // Backdate a middle log entry by one day
    public static function editLogCreatedAt(PDO $pdo, int $documentId): void
    {
        $log = self::middleLog($pdo, $documentId);
        $pdo->prepare('UPDATE document_logs SET created_at = created_at - INTERVAL 1 DAY WHERE id = ?')->execute([$log['id']]);
    }

    // Reattribute a middle log entry to the admin account
    public static function editLogUserId(PDO $pdo, int $documentId): void
    {
        $log = self::middleLog($pdo, $documentId);
        $adminId = (int) self::userId($pdo, 'admin');
        if ((int) $log['user_id'] === $adminId) {
            throw new RuntimeException('Middle log is already attributed to admin.');
        }
        $pdo->prepare('UPDATE document_logs SET user_id = ? WHERE id = ?')->execute([$adminId, $log['id']]);
    }

    // Overwrite the state hash sealed in the newest log entry
    public static function editNewestLogStateHash(PDO $pdo, int $documentId): void
    {
        $logs = self::logs($pdo, $documentId);
        $newest = end($logs);
        $pdo->prepare('UPDATE document_logs SET document_state_hash = ? WHERE id = ?')->execute([hash('sha256', 'forged state'), $newest['id']]);
    }

    // Delete one entry from the middle of the chain
    public static function deleteMiddleLog(PDO $pdo, int $documentId): void
    {
        $log = self::middleLog($pdo, $documentId);
        $pdo->prepare('DELETE FROM document_logs WHERE id = ?')->execute([$log['id']]);
    }

    // Swap the complete contents (everything except the id) of two adjacent middle entries, i.e. reorder history
    public static function swapTwoLogPayloads(PDO $pdo, int $documentId): void
    {
        $logs = self::logs($pdo, $documentId);
        $index = intdiv(count($logs), 2);
        [$a, $b] = [$logs[$index - 1], $logs[$index]];

        // Each row receives the other row's columns; action_category is generated and follows action
        $columns = ['user_id', 'action', 'remarks', 'hash', 'previous_hash', 'signature', 'document_state_hash', 'document_snapshot', 'created_at', 'updated_at'];
        $set = implode(', ', array_map(fn ($c) => "{$c} = ?", $columns));
        $update = $pdo->prepare("UPDATE document_logs SET {$set} WHERE id = ?");
        foreach ([[$a, $b], [$b, $a]] as [$target, $source]) {
            $update->execute([...array_map(fn ($c) => $source[$c], $columns), $target['id']]);
        }
    }

    // Delete the newest entry of the chain (the case decides whether a checkpoint was written first)
    public static function deleteTailLog(PDO $pdo, int $documentId): void
    {
        $logs = self::logs($pdo, $documentId);
        $pdo->prepare('DELETE FROM document_logs WHERE id = ?')->execute([end($logs)['id']]);
    }

    // ---------------------------------------------------------------
    // Forged entries that cover a title edit
    // ---------------------------------------------------------------

    // Edit the title and append an entry sealing the new state: correct chain hash, garbage signature
    public static function appendForgedLogRecomputedHashBadSignature(PDO $pdo, int $documentId): void
    {
        self::editTitle($pdo, $documentId);
        self::appendForgedLog($pdo, $documentId, fn (string $message) => base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES)));
    }

    // Edit the title, register an attacker key as the Records Officer's current key, and append a properly signed entry
    public static function appendLogSignedWithInjectedAttackerKey(PDO $pdo, int $documentId): void
    {
        self::editTitle($pdo, $documentId);

        // The attacker key becomes active exactly at the forged entry's time, so older entries still verify
        $keypair = sodium_crypto_sign_keypair();
        $createdAt = self::forgedTimestamp();
        self::injectKey($pdo, (int) self::userId($pdo, 'records.unit'), sodium_crypto_sign_publickey($keypair), $createdAt);

        $secret = sodium_crypto_sign_secretkey($keypair);
        self::appendForgedLog($pdo, $documentId, fn (string $message) => base64_encode(sodium_crypto_sign_detached($message, $secret)), $createdAt);
    }

    // Edit the title and rebuild the whole chain over the new state, re-signing user entries with attacker keys.
    // Models a database-only insider: the server key lives in .env, so system-signed entries are kept as they are.
    public static function rewriteWholeChainWithAttackerKeys(PDO $pdo, int $documentId): void
    {
        self::editTitle($pdo, $documentId);
        $newStateHash = HashChainService::calculateStateHash(self::document($pdo, $documentId));
        $logs = self::logs($pdo, $documentId);

        // One attacker key per signer, active from one hour before the first entry
        $activatedAt = date('Y-m-d H:i:s', strtotime($logs[0]['created_at']) - 3600);
        $secrets = [];
        foreach ($logs as $log) {
            // Skip system entries and signers that already have an attacker key
            if ($log['user_id'] === null || isset($secrets[$log['user_id']])) {
                continue;
            }
            $keypair = sodium_crypto_sign_keypair();
            self::injectKey($pdo, (int) $log['user_id'], sodium_crypto_sign_publickey($keypair), $activatedAt);
            $secrets[$log['user_id']] = sodium_crypto_sign_secretkey($keypair);
        }

        // Re-link and re-seal every entry in order
        $previousHash = HashChainService::GENESIS_HASH;
        $update = $pdo->prepare('UPDATE document_logs SET previous_hash = ?, hash = ?, signature = ?, document_state_hash = ? WHERE id = ?');
        foreach ($logs as $log) {
            $userId = $log['user_id'] !== null ? (int) $log['user_id'] : null;
            $epoch = HashChainService::logTimestamp($log['created_at']);
            $remarks = (string) ($log['remarks'] ?? '');

            // System entries keep their sealed state and server signature; user entries get the new state and an attacker signature
            if ($userId === null) {
                $stateHash = (string) $log['document_state_hash'];
                $signature = (string) $log['signature'];
            } else {
                $stateHash = $newStateHash;
                $message = HashChainService::signedMessage($documentId, $log['action'], $epoch, $previousHash, $stateHash, $remarks);
                $signature = base64_encode(sodium_crypto_sign_detached($message, $secrets[$log['user_id']]));
            }

            $hash = HashChainService::chainHash($documentId, $userId, $log['action'], $epoch, $previousHash, $stateHash, $remarks, $signature);
            $update->execute([$previousHash, $hash, $signature, $stateHash, $log['id']]);
            $previousHash = $hash;
        }
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    // Append a Records Officer entry that seals the current live state, using the given signer callback
    private static function appendForgedLog(PDO $pdo, int $documentId, callable $sign, ?string $createdAt = null): void
    {
        $logs = self::logs($pdo, $documentId);
        $previousHash = end($logs)['hash'];
        $document = self::document($pdo, $documentId);
        $stateHash = HashChainService::calculateStateHash($document);
        $userId = (int) self::userId($pdo, 'records.unit');
        $createdAt ??= self::forgedTimestamp();
        $epoch = HashChainService::logTimestamp($createdAt);

        // Build the entry exactly as createLog() would, except for the signature
        $message = HashChainService::signedMessage($documentId, self::FORGED_ACTION, $epoch, $previousHash, $stateHash, self::FORGED_REMARKS);
        $signature = $sign($message);
        $hash = HashChainService::chainHash($documentId, $userId, self::FORGED_ACTION, $epoch, $previousHash, $stateHash, self::FORGED_REMARKS, $signature);

        $pdo->prepare(
            'INSERT INTO document_logs (document_id, user_id, action, remarks, previous_hash, hash, signature, document_state_hash, document_snapshot, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$documentId, $userId, self::FORGED_ACTION, self::FORGED_REMARKS, $previousHash, $hash, $signature, $stateHash, json_encode($document), $createdAt]);
    }

    // Insert an attacker public key into a user's key history
    private static function injectKey(PDO $pdo, int $userId, string $rawPublicKey, string $activatedAt): void
    {
        $pdo->prepare('INSERT INTO user_public_key_histories (user_id, public_key, activated_at, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())')
            ->execute([$userId, base64_encode($rawPublicKey), $activatedAt]);
    }

    // A timestamp one minute after now (Manila time), safely after every genuine entry
    private static function forgedTimestamp(): string
    {
        return (new \DateTimeImmutable('+1 minute', new \DateTimeZone(HashChainService::APP_TIMEZONE)))->format('Y-m-d H:i:s');
    }

    // Fetch the document row
    private static function document(PDO $pdo, int $documentId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = ?');
        $stmt->execute([$documentId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Fetch the document's chain in order
    private static function logs(PDO $pdo, int $documentId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM document_logs WHERE document_id = ? ORDER BY id ASC');
        $stmt->execute([$documentId]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // The middle-entry tactics need at least three entries
        if (count($logs) < 3) {
            throw new RuntimeException("Document {$documentId} has too few log entries to tamper with.");
        }

        return $logs;
    }

    // A user-signed entry from the middle of the chain (never the first or the newest)
    private static function middleLog(PDO $pdo, int $documentId): array
    {
        $logs = self::logs($pdo, $documentId);

        return $logs[intdiv(count($logs), 2)];
    }

    // Look up a seeded user's id by username
    private static function userId(PDO $pdo, string $username): int
    {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$username]);

        return (int) $stmt->fetchColumn();
    }
}
