<?php

/**
 * Re-date and re-seal document chains for seed.js's "time travel" step.
 *
 * In log format v2 the signature covers the timestamp and the previous hash, so moving a
 * log's created_at means the entry must be signed and chained again. seed.js chooses the new
 * dates; this script applies them and re-seals every affected chain with the same helpers the
 * live system uses. Users sign with the seed PIN ('password'); system entries with the server key.
 *
 * Usage: php scripts/seeders/resign-logs.php <plan.json>
 * plan.json: { "<document_id>": ["YYYY-mm-dd HH:ii:ss", ...one date per log, in log id order] }
 */

define('BASE_PATH', dirname(dirname(__DIR__)));
require BASE_PATH . '/vendor/autoload.php';
date_default_timezone_set(\App\Integrity\HashChainMicroShardingBuilder::APP_TIMEZONE);

use App\Core\Database;
use App\Integrity\DigitalSignature;
use App\Integrity\HashChainMicroShardingBuilder;

$plan = json_decode((string) file_get_contents($argv[1] ?? ''), true);
if (!is_array($plan)) {
    fwrite(STDERR, "resign-logs: invalid or missing plan file\n");
    exit(1);
}

$db = Database::getInstance();
$conn = $db->getConnection();
$pin = 'password';
$now = time();
$userKeys = [];

/**
 * Return a user's decrypted signing key, unwrapping it once per run.
 */
$keyFor = function (int $userId) use ($db, $pin, &$userKeys) {
    if (!array_key_exists($userId, $userKeys)) {
        $row = $db->query("SELECT private_key FROM users WHERE id = :id", ['id' => $userId])->fetch();
        $userKeys[$userId] = $row && $row['private_key'] ? DigitalSignature::decryptPrivateKey($row['private_key'], $pin) : null;
    }
    return $userKeys[$userId];
};

$conn->beginTransaction();
try {
    foreach ($plan as $documentId => $dates) {
        $documentId = (int) $documentId;
        $logs = $db->query("SELECT * FROM document_logs WHERE document_id = :id ORDER BY id ASC", ['id' => $documentId])->fetchAll();

        $previousHash = HashChainMicroShardingBuilder::GENESIS_HASH;
        $firstDate = null;
        $lastDate = null;
        $lastTs = 0;

        foreach ($logs as $i => $log) {
            // Never date a log in the future, and keep dates non-decreasing along the chain
            $ts = isset($dates[$i]) ? strtotime($dates[$i]) : strtotime($log['created_at']);
            $ts = max(min($ts, $now), $lastTs);
            $lastTs = $ts;
            $createdAt = date('Y-m-d H:i:s', $ts);
            $firstDate = $firstDate ?? $createdAt;
            $lastDate = $createdAt;

            // Re-sign and re-chain with the v2 helpers
            $userId = $log['user_id'] !== null ? (int) $log['user_id'] : null;
            $epoch = HashChainMicroShardingBuilder::logTimestamp($createdAt);
            $remarks = (string) ($log['remarks'] ?? '');
            $message = HashChainMicroShardingBuilder::signedMessage($documentId, $log['action'], $epoch, $previousHash, $log['document_state_hash'], $remarks);
            $secret = $userId !== null ? $keyFor($userId) : null;
            if ($userId !== null && $secret === null) {
                throw new \RuntimeException("User {$userId} has no key unlockable with the seed PIN");
            }
            $signature = $secret !== null ? base64_encode(sodium_crypto_sign_detached($message, $secret)) : DigitalSignature::signAsSystem($message);
            $hash = HashChainMicroShardingBuilder::chainHash($documentId, $userId, $log['action'], $epoch, $previousHash, $log['document_state_hash'], $remarks, $signature);

            $db->query(
                "UPDATE document_logs SET created_at = :c, updated_at = :u, previous_hash = :p, hash = :h, signature = :s WHERE id = :id",
                ['c' => $createdAt, 'u' => $createdAt, 'p' => $previousHash, 'h' => $hash, 's' => $signature, 'id' => $log['id']]
            );
            $previousHash = $hash;
        }

        // Move the document's own timestamps to match its chain
        if ($firstDate !== null) {
            $actions = array_column($logs, 'action');
            $db->query(
                "UPDATE documents SET created_at = :c, updated_at = :u,
                    declined_at = IF(:declined, :d1, declined_at),
                    released_at = IF(:released, :d2, released_at)
                 WHERE id = :id",
                [
                    'c' => $firstDate, 'u' => $lastDate,
                    'declined' => in_array('Document Declined', $actions, true) ? 1 : 0, 'd1' => $lastDate,
                    'released' => in_array('Document Released', $actions, true) ? 1 : 0, 'd2' => $lastDate,
                    'id' => $documentId
                ]
            );
        }
    }
    $conn->commit();
} catch (\Throwable $e) {
    $conn->rollBack();
    fwrite(STDERR, "resign-logs failed: " . $e->getMessage() . "\n");
    exit(1);
}
