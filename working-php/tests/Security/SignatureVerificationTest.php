<?php

namespace Tests\Security;

use App\Common\Database\Database;
use App\Integrity\DigitalSignature;
use App\Integrity\HashChainMicroShardingBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixtures;
use App\Research\ResultWriter;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Ed25519 signature verification over log format v2 (thesis section 3.6(c)).
 *
 * Builds complete lifecycles plus a declined document (user-signed and system-signed entries),
 * re-derives every signed message from the stored row and verifies it, then checks that altering
 * any signed field breaks verification and that a signature key reset keeps old entries valid.
 * Rows go to storage/test-results/signatures.csv. A failing assertion is a FINDING.
 */
class SignatureVerificationTest extends TestCase
{
    private const CSV = 'signatures.csv';
    private const ROUTE = ['Cash Unit', 'Personnel Unit'];

    // One id for every row written by this PHPUnit process
    private static ?string $runId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Key histories are cached per process; the database was just rebuilt, so drop stale entries
        $this->resetKeyCache();
    }

    /**
     * Every stored signature in a mixed test data set verifies against its rebuilt signed message.
     */
    public function test_every_signature_in_test_data_verifies(): void
    {
        // Three complete lifecycles and one declined document
        for ($i = 1; $i <= 3; $i++) {
            $document = Fixtures::submit(['title' => "Lifecycle {$i}"]);
            Fixtures::advanceTo($document['id'], 'completed', self::ROUTE);
        }
        $declined = Fixtures::submit(['title' => 'Declined']);
        Fixtures::advanceTo($declined['id'], 'declined');

        // Verify each log row from its stored columns
        $logs = Database::getInstance()->query('SELECT * FROM document_logs ORDER BY id')->fetchAll();
        $userSigned = 0;
        $systemSigned = 0;
        $valid = 0;
        $invalid = [];
        foreach ($logs as $log) {
            $log['user_id'] === null ? $systemSigned++ : $userSigned++;

            if ($this->verifyRow($log)['valid']) {
                $valid++;
            } else {
                $invalid[] = sprintf('log %d (%s, user %s)', $log['id'], $log['action'], var_export($log['user_id'], true));
            }
        }

        $this->record('all_signatures_verify', count($logs), $userSigned, $systemSigned, $valid, count($invalid));

        // The data set must contain both signer kinds, otherwise the test proves less than claimed
        $this->assertGreaterThan(0, $userSigned, 'Harness error: no user-signed entries in the data set.');
        $this->assertGreaterThan(0, $systemSigned, 'Harness error: no system-signed entries in the data set.');
        $this->assertSame([], $invalid, 'FINDING [signature_verification]: signatures failed to verify: ' . implode('; ', $invalid));
    }

    /**
     * Changing any one signed field makes a user-signed and a system-signed entry fail verification.
     */
    #[DataProvider('signedFields')]
    public function test_altering_any_signed_field_fails_verification(string $field): void
    {
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'completed', ['Cash Unit']);

        // One user-signed and one system-signed entry (the submission log is signed by the system)
        $logs = Database::getInstance()->query('SELECT * FROM document_logs WHERE document_id = :d ORDER BY id', [':d' => $document['id']])->fetchAll();
        $targets = [];
        foreach ($logs as $log) {
            $kind = $log['user_id'] === null ? 'system' : 'user';
            $targets[$kind] ??= $log;
        }
        $this->assertCount(2, $targets, 'Harness error: need both a user-signed and a system-signed entry.');

        foreach ($targets as $kind => $log) {
            // Untouched entry verifies, so a later failure is caused by the alteration alone
            $this->assertTrue($this->verifyRow($log)['valid'], "Baseline {$kind} entry must verify before alteration.");

            // Alter exactly one signed field
            $altered = $log;
            switch ($field) {
                case 'document_id':
                    $altered['document_id'] = (int) $log['document_id'] + 1;
                    break;
                case 'action':
                    $altered['action'] = $log['action'] . ' (altered)';
                    break;
                case 'epoch':
                    $altered['created_at'] = date('Y-m-d H:i:s', strtotime($log['created_at']) + 1);
                    break;
                case 'previous_hash':
                    $altered['previous_hash'] = hash('sha256', 'forged-parent');
                    break;
                case 'state_hash':
                    $altered['document_state_hash'] = hash('sha256', 'forged-state');
                    break;
                case 'remarks':
                    $altered['remarks'] = $log['remarks'] . ' altered';
                    break;
            }

            $result = $this->verifyRow($altered);
            $this->record("alter_{$field}_{$kind}", 1, $kind === 'user' ? 1 : 0, $kind === 'system' ? 1 : 0, $result['valid'] ? 1 : 0, $result['valid'] ? 0 : 1);
            $this->assertFalse($result['valid'], "FINDING [alter_{$field}]: a {$kind}-signed entry still verified after altering {$field}.");
        }
    }

    /**
     * Field names the signed message covers (epoch is the log timestamp, shifted by one second).
     */
    public static function signedFields(): array
    {
        return [
            'document_id' => ['document_id'],
            'action' => ['action'],
            'epoch' => ['epoch'],
            'previous_hash' => ['previous_hash'],
            'state_hash' => ['state_hash'],
            'remarks' => ['remarks'],
        ];
    }

    /**
     * After a signature reset (new key, old period closed) old entries still verify with the old
     * key and entries signed afterwards verify with the new key.
     */
    public function test_signature_survives_key_reset(): void
    {
        $db = Database::getInstance();
        $cashId = $this->fx['users']['cash.unit'];

        // Document signed with the original cash.unit key
        $before = Fixtures::submit(['title' => 'Before reset']);
        Fixtures::advanceTo($before['id'], 'completed', ['Cash Unit']);
        $oldKey = $db->query('SELECT public_key FROM users WHERE id = :id', [':id' => $cashId])->fetch()['public_key'];

        // Keep the key period boundary (one-second timestamps) strictly between old and new entries
        sleep(2);
        $this->resetSignature($cashId, TestDatabase::PIN);
        sleep(2);

        // The in-process history cache predates the reset, exactly as IntegrityCheckJob would not see it
        $this->resetKeyCache();

        // Document signed with the new key
        $after = Fixtures::submit(['title' => 'After reset']);
        Fixtures::advanceTo($after['id'], 'completed', ['Cash Unit']);
        $newKey = $db->query('SELECT public_key FROM users WHERE id = :id', [':id' => $cashId])->fetch()['public_key'];
        $this->assertNotSame($oldKey, $newKey, 'Harness error: the reset did not change the public key.');

        // Verify every cash.unit entry and check which key it resolved to
        $logs = $db->query('SELECT * FROM document_logs WHERE user_id = :u ORDER BY id', [':u' => $cashId])->fetchAll();
        $valid = 0;
        $invalid = [];
        $oldCount = 0;
        $newCount = 0;
        foreach ($logs as $log) {
            $resolved = DigitalSignature::publicKeyAt($cashId, $log['created_at']);
            if ($resolved === $oldKey) {
                $oldCount++;
            } elseif ($resolved === $newKey) {
                $newCount++;
            }

            if ($this->verifyRow($log)['valid']) {
                $valid++;
            } else {
                $invalid[] = sprintf('log %d (%s at %s)', $log['id'], $log['action'], $log['created_at']);
            }
        }

        $this->record('key_reset', count($logs), count($logs), 0, $valid, count($invalid));

        $this->assertGreaterThan(0, $oldCount, 'Harness error: no entries resolved to the old key.');
        $this->assertGreaterThan(0, $newCount, 'Harness error: no entries resolved to the new key.');
        $this->assertSame([], $invalid, 'FINDING [key_reset]: entries failed to verify after a key reset: ' . implode('; ', $invalid));
    }

    // Rebuild the signed message from a log row and verify it the way the integrity audit does
    private function verifyRow(array $log): array
    {
        $message = HashChainMicroShardingBuilder::signedMessage(
            (int) $log['document_id'],
            (string) $log['action'],
            HashChainMicroShardingBuilder::logTimestamp((string) $log['created_at']),
            (string) $log['previous_hash'],
            (string) $log['document_state_hash'],
            (string) $log['remarks']
        );

        return DigitalSignature::verifySignature($log['user_id'] === null ? null : (int) $log['user_id'], (string) $log['created_at'], $message, $log['signature']);
    }

    // Reproduce SecurityKeyController::store: new keypair wrapped with the PIN, old periods closed, new period opened
    private function resetSignature(int $userId, string $pin): void
    {
        $db = Database::getInstance();
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        // Encrypt the private key with the PIN (Argon2id + AES-256-CBC)
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = sodium_crypto_pwhash(32, $pin, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $iv = random_bytes(16);
        $encryptedPriv = openssl_encrypt($secretKey, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        $pubB64 = base64_encode($publicKey);
        $privB64 = base64_encode($salt . $iv . $encryptedPriv);
        $now = date('Y-m-d H:i:s');

        // Store the new key on the user
        $db->query('UPDATE users SET public_key = :pub, private_key = :priv, security_key_set_at = :now WHERE id = :id', [
            'pub' => $pubB64, 'priv' => $privB64, 'now' => $now, 'id' => $userId,
        ]);

        // Close every key period still open at this moment
        $db->query('UPDATE user_public_key_histories SET deactivated_at = :now, updated_at = :now2 WHERE user_id = :id AND (deactivated_at IS NULL OR deactivated_at > :now3)', [
            'now' => $now, 'now2' => $now, 'now3' => $now, 'id' => $userId,
        ]);

        // Open the new key period
        $db->query('INSERT INTO user_public_key_histories (user_id, public_key, activated_at, created_at, updated_at) VALUES (:user_id, :pub, :now, :now2, :now3)', [
            'user_id' => $userId, 'pub' => $pubB64, 'now' => $now, 'now2' => $now, 'now3' => $now,
        ]);
    }

    // Empty DigitalSignature's private static key-history cache
    private function resetKeyCache(): void
    {
        DigitalSignature::clearKeyCache();
    }

    // Append one result row to signatures.csv
    private function record(string $test, int $total, int $userSigned, int $systemSigned, int $valid, int $invalid): void
    {
        ResultWriter::append(self::CSV, [
            'test' => $test,
            'total_entries' => $total,
            'user_signed' => $userSigned,
            'system_signed' => $systemSigned,
            'valid' => $valid,
            'invalid' => $invalid,
            'run_id' => self::runId(),
            'git_commit' => self::gitCommit(),
        ]);
    }

    // One id per PHPUnit process
    private static function runId(): string
    {
        return self::$runId ??= date('Ymd\THis') . '-' . bin2hex(random_bytes(3));
    }

    // git describe of the working tree ('unknown' outside a repository)
    private static function gitCommit(): string
    {
        $commit = trim((string) @shell_exec('git -C ' . escapeshellarg(BASE_PATH) . ' describe --always --dirty 2>/dev/null'));

        return $commit !== '' ? $commit : 'unknown';
    }
}
