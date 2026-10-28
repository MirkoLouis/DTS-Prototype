<?php

namespace Tests\Workflow;

use App\Core\Database;
use App\Core\IntegrityManager;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * IntegrityManager memoizes key histories per process. A long-lived process that verifies across
 * a key reset must clear that cache (SecurityKeyController::store and UserController::resetSignature do).
 */
class KeyCacheInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The database was just rebuilt, so drop key histories memoized by earlier tests
        IntegrityManager::clearKeyCache();
    }

    /**
     * Old and new entries both verify when the cache is cleared after a key reset in the same process.
     */
    public function test_new_entry_verifies_after_key_reset_in_same_process(): void
    {
        $cashId = $this->fx['users']['cash.unit'];

        // Entry signed with the original key; verifying it fills the cache
        $old = $this->signedLogs('Before reset');
        $this->assertNotSame([], $old, 'Harness error: no cash.unit entries were signed.');
        $this->assertTrue($this->allValid($old), 'Entries signed with the original key must verify.');

        // Reset the key as the controller does, including its cache clear
        sleep(2);
        $this->resetSignature($cashId, TestDatabase::PIN, true);
        sleep(2);

        // Entry signed with the new key
        $this->signedLogs('After reset');
        $all = Database::getInstance()->query('SELECT * FROM document_logs WHERE user_id = :u ORDER BY id', [':u' => $cashId])->fetchAll();
        $this->assertGreaterThan(count($old), count($all), 'Harness error: no new entries were signed.');

        // Both the old and the new entries verify
        $this->assertTrue($this->allValid($all), 'Old and new entries must verify after the cache clear.');
    }

    /**
     * Documents the bug the clear prevents: a stale cache reports the new entry as invalid.
     */
    public function test_stale_cache_without_clear_fails_new_entry(): void
    {
        $cashId = $this->fx['users']['cash.unit'];

        // Fill the cache with the original key history
        $old = $this->signedLogs('Before reset');
        $this->assertTrue($this->allValid($old));

        // Reset the key WITHOUT clearing the cache
        sleep(2);
        $this->resetSignature($cashId, TestDatabase::PIN, false);
        sleep(2);

        // The new entry resolves to the stale (old) key and fails
        $this->signedLogs('After reset');
        $all = Database::getInstance()->query('SELECT * FROM document_logs WHERE user_id = :u ORDER BY id', [':u' => $cashId])->fetchAll();
        $newest = end($all);
        $this->assertFalse($this->verifyRow($newest), 'A stale key cache must report the new entry as invalid.');

        // Clearing the cache repairs it
        IntegrityManager::clearKeyCache();
        $this->assertTrue($this->verifyRow($newest));
    }

    // Submit and complete one document through Cash Unit; return all cash.unit log rows so far
    private function signedLogs(string $title): array
    {
        $document = Fixtures::submit(['title' => $title]);
        Fixtures::advanceTo($document['id'], 'completed', ['Cash Unit']);

        return Database::getInstance()->query('SELECT * FROM document_logs WHERE user_id = :u ORDER BY id', [':u' => $this->fx['users']['cash.unit']])->fetchAll();
    }

    // True when every given log row verifies
    private function allValid(array $logs): bool
    {
        foreach ($logs as $log) {
            if (!$this->verifyRow($log)) {
                return false;
            }
        }

        return true;
    }

    // Rebuild the signed message from a log row and verify it the way the integrity audit does
    private function verifyRow(array $log): bool
    {
        $message = IntegrityManager::signedMessage(
            (int) $log['document_id'],
            (string) $log['action'],
            IntegrityManager::logTimestamp((string) $log['created_at']),
            (string) $log['previous_hash'],
            (string) $log['document_state_hash'],
            (string) $log['remarks']
        );

        return IntegrityManager::verifySignature((int) $log['user_id'], (string) $log['created_at'], $message, $log['signature'])['valid'];
    }

    // Reproduce SecurityKeyController::store; $clearCache mirrors its clearKeyCache() call
    private function resetSignature(int $userId, string $pin, bool $clearCache): void
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

        // Drop the memoized key history, as the controller does right after the rows are written
        if ($clearCache) {
            IntegrityManager::clearKeyCache();
        }
    }
}
