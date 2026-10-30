<?php

namespace App\Jobs;

use App\Common\Config\Config;
use App\Common\Database\Database;
use App\Integrity\DigitalSignature;
use App\Integrity\HashChainMicroShardingBuilder;

/**
 * Chain-head checkpoints: external evidence for the per-document hash chains.
 *
 * Independent chains cannot detect that a chain's newest entries (or a whole chain) were deleted,
 * because nothing links to them. A checkpoint records every document's current head hash in a
 * file outside the database, signed with the server key. Verification then requires every
 * recorded head to still exist in its chain. Copy checkpoint files off this machine (USB, email,
 * print) to make them independent of a database administrator.
 */
class ChainCheckpointJob
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Directory that holds checkpoint files.
     *
     * The test suite (app.env = testing) uses its own directory, so test checkpoints are never
     * picked up as the real app's latest checkpoint.
     */
    public static function directory(): string
    {
        return BASE_PATH . (Config::get('app', 'env') === 'testing' ? '/storage/checkpoints-test' : '/storage/checkpoints');
    }

    /**
     * Write a new checkpoint of all chain heads and return its file path.
     */
    public function create(): string
    {
        $db = Database::getInstance();

        // Newest entry of every document's chain
        $rows = $db->query(
            "SELECT dl.document_id, dl.hash
             FROM document_logs dl
             JOIN (SELECT document_id, MAX(id) AS max_id FROM document_logs GROUP BY document_id) heads
               ON heads.max_id = dl.id
             ORDER BY dl.document_id ASC"
        )->fetchAll();

        $heads = [];
        foreach ($rows as $row) {
            $heads[(string) $row['document_id']] = $row['hash'];
        }

        // Root over the ordered head list, signed together with the checkpoint time
        $createdAt = (new \DateTimeImmutable('now', new \DateTimeZone(HashChainMicroShardingBuilder::APP_TIMEZONE)))->format('Y-m-d H:i:s');
        $root = hash('sha256', json_encode($heads, self::JSON_FLAGS));
        $signature = DigitalSignature::signAsSystem("checkpoint|{$createdAt}|{$root}");

        // Write the checkpoint file
        if (!is_dir(self::directory())) {
            mkdir(self::directory(), 0755, true);
        }
        $path = self::directory() . '/checkpoint-' . str_replace([' ', ':'], ['_', ''], $createdAt) . '.json';
        file_put_contents($path, json_encode([
            'created_at' => $createdAt,
            'document_count' => count($heads),
            'root' => $root,
            'signature' => $signature,
            'heads' => $heads
        ], self::JSON_FLAGS | JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * Verify the newest checkpoint against the current chains.
     *
     * @return array|null null when no checkpoint exists yet
     */
    public function verifyLatest(): ?array
    {
        // Pick the newest checkpoint file (names sort chronologically)
        $files = glob(self::directory() . '/checkpoint-*.json') ?: [];
        if (empty($files)) {
            return null;
        }
        sort($files);
        $path = end($files);
        $checkpoint = json_decode((string) file_get_contents($path), true);

        $result = [
            'file' => basename($path),
            'created_at' => $checkpoint['created_at'] ?? null,
            'document_count' => count($checkpoint['heads'] ?? []),
            'file_intact' => false,
            'missing_heads' => 0,
            'missing_document_ids' => []
        ];

        // 1. The file itself must be untouched: root recomputes and the server signature verifies
        $heads = $checkpoint['heads'] ?? [];
        $root = hash('sha256', json_encode($heads, self::JSON_FLAGS));
        $publicKey = base64_decode(Config::get('app', 'system_signing_public', ''), true);
        $signature = base64_decode((string) ($checkpoint['signature'] ?? ''), true);
        if ($root === ($checkpoint['root'] ?? null) && $publicKey !== false && $signature !== false
            && strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES && strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES) {
            $result['file_intact'] = sodium_crypto_sign_verify_detached($signature, "checkpoint|{$checkpoint['created_at']}|{$root}", $publicKey);
        }

        // 2. Every recorded head must still exist in its document's chain
        $db = Database::getInstance();
        foreach (array_chunk($heads, 1000, true) as $chunk) {
            $conditions = [];
            $params = [];
            foreach ($chunk as $documentId => $hash) {
                $conditions[] = '(document_id = ? AND hash = ?)';
                $params[] = (int) $documentId;
                $params[] = $hash;
            }
            $found = $db->query("SELECT document_id FROM document_logs WHERE " . implode(' OR ', $conditions), $params)->fetchAll(\PDO::FETCH_COLUMN);
            $foundSet = array_flip(array_map('strval', $found));

            // Any head that is gone means entries were deleted after the checkpoint
            foreach (array_keys($chunk) as $documentId) {
                if (!isset($foundSet[(string) $documentId])) {
                    $result['missing_heads']++;
                    if (count($result['missing_document_ids']) < 1000) {
                        $result['missing_document_ids'][] = (int) $documentId;
                    }
                }
            }
        }

        return $result;
    }
}
