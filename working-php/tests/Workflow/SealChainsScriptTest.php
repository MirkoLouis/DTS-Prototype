<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Common\Security\IntegrityKey;
use App\Modules\Integrity\ChainSealService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Fixtures;
use Tests\Support\IntegrityAudit;
use Tests\TestCase;

/**
 * scripts/seal-chains.php seals existing data once, never overwrites a seal without an explicit flag,
 * and refuses without a key or against another database. Always run here with APP_ENV=testing.
 */
#[Group('thesis-integrity')]
class SealChainsScriptTest extends TestCase
{
    // Three finished documents, then every seal removed to imitate data from before the chain seal
    private function unsealedDocuments(): array
    {
        $documents = [];
        foreach (['completed', 'in_transit', 'declined'] as $status) {
            $document = Fixtures::submit();
            Fixtures::advanceTo($document['id'], $status, ['Cash Unit']);
            $documents[] = $document;
        }
        Database::getInstance()->query('UPDATE document_logs SET seal = NULL');
        Database::getInstance()->query('UPDATE documents SET chain_seal = NULL');

        return $documents;
    }

    // Run the script as a subprocess against the test database; $extraEnv may override the key
    private function runScript(array $arguments = [], array $extraEnv = []): array
    {
        $command = array_merge([PHP_BINARY, BASE_PATH . '/scripts/seal-chains.php'], $arguments);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, array_merge(getenv(), ['APP_ENV' => 'testing'], $extraEnv));
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $stdout, (string) $stderr];
    }

    // Every seal column of the database, for before/after comparison
    private function sealState(): array
    {
        $db = Database::getInstance();

        return [
            $db->query('SELECT id, seal FROM document_logs ORDER BY id')->fetchAll(),
            $db->query('SELECT id, chain_seal FROM documents ORDER BY id')->fetchAll(),
        ];
    }

    // Unsealed data gets sealed, the counts are printed, and the integrity check then reports every seal valid
    public function test_seals_unsealed_data(): void
    {
        $this->unsealedDocuments();
        $entryCount = (int) Database::getInstance()->query('SELECT COUNT(*) c FROM document_logs')->fetch()['c'];

        [$exit, $stdout] = $this->runScript();

        $this->assertSame(0, $exit, $stdout);
        $this->assertStringContainsString('Documents sealed: 3', $stdout);
        $this->assertStringContainsString("Entries sealed:   {$entryCount}", $stdout);
        $results = IntegrityAudit::run($this->fx['users']['admin']);
        $this->assertTrue($results['seal_configured']);
        $this->assertSame(0, $results['invalid_entry_seals']);
        $this->assertSame(0, $results['invalid_chain_seals']);
    }

    // A second run changes nothing
    public function test_second_run_changes_nothing(): void
    {
        $this->unsealedDocuments();
        $this->runScript();
        $after = $this->sealState();

        [$exit, $stdout] = $this->runScript();

        $this->assertSame(0, $exit, $stdout);
        $this->assertStringContainsString('Documents sealed: 0', $stdout);
        $this->assertStringContainsString('Entries sealed:   0', $stdout);
        $this->assertSame($after, $this->sealState());
    }

    // Without a configured key nothing is sealed
    public function test_refuses_without_a_key(): void
    {
        $this->unsealedDocuments();
        $before = $this->sealState();

        [$exit, , $stderr] = $this->runScript([], [IntegrityKey::TEST_OVERRIDE_VARIABLE => IntegrityKey::TEST_NO_KEY]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('APP_INTEGRITY_KEY', $stderr);
        $this->assertSame($before, $this->sealState());
    }

    // An existing seal is never overwritten: a tampered, already sealed document stays flagged and the exit code says so
    public function test_does_not_overwrite_an_existing_seal(): void
    {
        $documents = $this->unsealedDocuments();
        $this->runScript();
        $db = Database::getInstance();

        // Tamper one sealed document and append an entry without a seal to another
        $db->query("UPDATE documents SET status = 'processing' WHERE id = :id", [':id' => $documents[0]['id']]);
        $logs = $db->query('SELECT * FROM document_logs WHERE document_id = :id ORDER BY id', [':id' => $documents[1]['id']])->fetchAll();
        $db->query('INSERT INTO document_logs (document_id, action, hash, previous_hash, created_at) VALUES (:d, :a, :h, :p, NOW())', [':d' => $documents[1]['id'], ':a' => 'Received', ':h' => hash('sha256', 'x'), ':p' => end($logs)['hash']]);
        $before = $this->sealState();

        [$exit, $stdout] = $this->runScript();

        $this->assertSame(2, $exit, $stdout);
        $this->assertStringContainsString('Left untouched', $stdout);
        $this->assertStringContainsString('seal missing on a sealed database: review', $stdout);
        $this->assertStringContainsString($documents[1]['tracking_code'], $stdout);
        $this->assertSame($before, $this->sealState());
        $this->assertFalse(ChainSealService::documentSealIsValid($db->query('SELECT * FROM documents WHERE id = :id', [':id' => $documents[0]['id']])->fetch()));
    }

    // Overwriting needs the flag AND the database name; the flag alone refuses
    public function test_overwrite_flag_needs_the_database_name(): void
    {
        $documents = $this->unsealedDocuments();
        $this->runScript();
        $db = Database::getInstance();
        $db->query("UPDATE documents SET status = 'processing' WHERE id = :id", [':id' => $documents[0]['id']]);
        $before = $this->sealState();

        // The flag alone refuses and changes nothing
        [$exit, , $stderr] = $this->runScript(['--overwrite-existing-seals']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('naming the database', $stderr);
        $this->assertSame($before, $this->sealState());

        // The flag with the configured database name overwrites (and so accepts the edit as the new truth)
        [$exit, $stdout, $stderr] = $this->runScript(['--overwrite-existing-seals', '--database=' . \App\Common\Config\Config::get('database', 'dbname')]);
        $this->assertSame(0, $exit, $stdout . $stderr);
        $this->assertStringContainsString('WARNING', $stderr);
        $this->assertTrue(ChainSealService::documentSealIsValid($db->query('SELECT * FROM documents WHERE id = :id', [':id' => $documents[0]['id']])->fetch()));
    }

    // Chains that do not fully verify are refused and reported by tracking code and reason; the others are sealed
    #[\PHPUnit\Framework\Attributes\DataProvider('brokenChains')]
    public function test_refuses_to_seal_a_chain_that_does_not_verify(string $tactic, string $reason): void
    {
        $documents = $this->unsealedDocuments();
        $this->breakChain($tactic, $documents[0]['id']);

        [$exit, $stdout] = $this->runScript();

        $this->assertSame(2, $exit, $stdout);
        $this->assertStringContainsString("REFUSED DOCUMENT {$documents[0]['tracking_code']}: ", $stdout);
        $this->assertStringContainsString($reason, $stdout);
        $db = Database::getInstance();
        $this->assertNull($db->query('SELECT chain_seal FROM documents WHERE id = :id', [':id' => $documents[0]['id']])->fetch()['chain_seal']);
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) c FROM document_logs WHERE document_id = :id AND seal IS NOT NULL', [':id' => $documents[0]['id']])->fetch()['c']);
        $this->assertNotNull($db->query('SELECT chain_seal FROM documents WHERE id = :id', [':id' => $documents[1]['id']])->fetch()['chain_seal']);
    }

    /**
     * tactic => [tactic, text the reported reason contains]
     */
    public static function brokenChains(): array
    {
        $rows = [
            ['edited_entry', 'block hash'],
            ['rehashed_entry', 'signature'],
            ['broken_signature', 'signature'],
            ['broken_link', 'link'],
            ['state_hash_mismatch', 'state hash'],
            ['snapshot_mismatch', 'snapshot'],
        ];

        return array_combine(array_column($rows, 0), $rows);
    }

    // The same verification guards --overwrite-existing-seals
    public function test_overwrite_also_refuses_a_chain_that_does_not_verify(): void
    {
        $documents = $this->unsealedDocuments();
        $this->runScript();
        $this->breakChain('edited_entry', $documents[0]['id']);
        $before = $this->sealState();

        [$exit, $stdout] = $this->runScript(['--overwrite-existing-seals', '--database=' . \App\Common\Config\Config::get('database', 'dbname')]);

        $this->assertSame(2, $exit, $stdout);
        $this->assertStringContainsString("REFUSED DOCUMENT {$documents[0]['tracking_code']}", $stdout);
        $docs = Database::getInstance()->query('SELECT id, chain_seal FROM documents WHERE id = :id', [':id' => $documents[0]['id']])->fetchAll();
        $this->assertSame(array_values(array_filter($before[1], fn ($r) => $r['id'] == $documents[0]['id'])), $docs);
    }

    // A NULLed seal on an already sealed database is not sealed again; a reviewed exception can be sealed by tracking code
    public function test_nulled_seal_on_a_sealed_database_needs_review(): void
    {
        $documents = $this->unsealedDocuments();
        $this->runScript();
        $db = Database::getInstance();
        $db->query('UPDATE document_logs SET seal = NULL WHERE document_id = :id ORDER BY id DESC LIMIT 1', [':id' => $documents[1]['id']]);
        $db->query('UPDATE documents SET chain_seal = NULL WHERE id = :id', [':id' => $documents[2]['id']]);
        $before = $this->sealState();

        // The default run reports both and seals neither
        [$exit, $stdout] = $this->runScript();
        $this->assertSame(2, $exit, $stdout);
        $this->assertStringContainsString("seal missing on a sealed database: review ({$documents[1]['tracking_code']})", $stdout);
        $this->assertStringContainsString("seal missing on a sealed database: review ({$documents[2]['tracking_code']})", $stdout);
        $this->assertSame($before, $this->sealState());

        // The narrow flag needs the database name
        [$exit] = $this->runScript(['--seal-documents=' . $documents[1]['tracking_code']]);
        $this->assertSame(1, $exit);
        $this->assertSame($before, $this->sealState());

        // With it, only the named document is sealed, with a warning and an audit record
        @unlink(\App\Modules\System\SystemAuditService::path());
        [$exit, $stdout, $stderr] = $this->runScript(['--seal-documents=' . $documents[1]['tracking_code'], '--database=' . \App\Common\Config\Config::get('database', 'dbname')]);
        $this->assertStringContainsString('WARNING', $stderr);
        $this->assertSame(0, $exit, $stdout . $stderr);
        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) c FROM document_logs WHERE document_id = :id AND seal IS NULL', [':id' => $documents[1]['id']])->fetch()['c']);
        $this->assertNull($db->query('SELECT chain_seal FROM documents WHERE id = :id', [':id' => $documents[2]['id']])->fetch()['chain_seal']);
        $audit = (string) file_get_contents(\App\Modules\System\SystemAuditService::path());
        $this->assertStringContainsString('chain_seals_written', $audit);
        $this->assertStringContainsString($documents[1]['tracking_code'], $audit);
    }

    // A guest submission after the migration creates a sealed document; that must not stop the legacy documents from being sealed
    public function test_a_submission_after_the_migration_does_not_block_sealing_legacy_documents(): void
    {
        $this->unsealedDocuments();
        $newDocument = Fixtures::submit();

        [$exit, $stdout] = $this->runScript();

        $this->assertSame(0, $exit, $stdout);
        $this->assertStringContainsString('Documents sealed: 3', $stdout);
        $this->assertStringNotContainsString('seal missing', $stdout);
        $this->assertSame(0, (int) Database::getInstance()->query('SELECT COUNT(*) c FROM documents WHERE chain_seal IS NULL')->fetch()['c']);
        $this->assertNotNull($newDocument['id']);
    }

    // Once the database is recorded as sealed (system audit log), NULLing every seal does not make it look new: nothing is sealed, every document is reported
    public function test_all_seals_nulled_on_a_recorded_sealed_database_are_reported_not_sealed(): void
    {
        $documents = $this->unsealedDocuments();
        $this->runScript();
        $this->assertTrue(ChainSealService::databaseIsSealed());
        Database::getInstance()->query('UPDATE document_logs SET seal = NULL');
        Database::getInstance()->query('UPDATE documents SET chain_seal = NULL');
        $before = $this->sealState();

        [$exit, $stdout] = $this->runScript();

        $this->assertSame(2, $exit, $stdout);
        $this->assertStringContainsString('Documents sealed: 0', $stdout);
        foreach ($documents as $document) {
            $this->assertStringContainsString("seal missing on a sealed database: review ({$document['tracking_code']})", $stdout);
        }
        $this->assertSame($before, $this->sealState());

        // The reviewed-exception flag is the only way through
        [$exit, $stdout, $stderr] = $this->runScript(['--seal-missing-on-sealed-database', '--database=' . \App\Common\Config\Config::get('database', 'dbname')]);
        $this->assertSame(0, $exit, $stdout . $stderr);
        $this->assertStringContainsString('Documents sealed: 3', $stdout);
    }

    // An audit log that fails verification cannot prove the database was never sealed: it counts as sealed (fail closed)
    public function test_a_tampered_audit_log_counts_as_sealed(): void
    {
        $this->unsealedDocuments();
        \App\Modules\System\SystemAuditService::record('backup_requested', ['user_id' => 1]);
        $path = \App\Modules\System\SystemAuditService::path();
        file_put_contents($path, str_replace('backup_requested', 'backup_deleted__', (string) file_get_contents($path)));
        $this->assertTrue(ChainSealService::databaseIsSealed());
        $before = $this->sealState();

        [$exit, $stdout] = $this->runScript();

        $this->assertSame(2, $exit, $stdout);
        $this->assertStringContainsString('Documents sealed: 0', $stdout);
        $this->assertSame($before, $this->sealState());
    }

    // Apply one way of breaking a chain to a document whose seals have been removed
    private function breakChain(string $tactic, int $documentId): void
    {
        $db = Database::getInstance();
        $logs = $db->query('SELECT * FROM document_logs WHERE document_id = :id ORDER BY id', [':id' => $documentId])->fetchAll();
        $middle = $logs[intdiv(count($logs), 2)];
        $newest = end($logs);

        match ($tactic) {
            'edited_entry' => $db->query("UPDATE document_logs SET remarks = 'edited' WHERE id = :id", [':id' => $middle['id']]),
            'rehashed_entry' => $this->rehash($middle, ['remarks' => 'edited and re-hashed']),
            'broken_signature' => $this->rehash($newest, ['signature' => base64_encode(random_bytes(64))]),
            'broken_link' => $db->query("UPDATE document_logs SET previous_hash = 'broken' WHERE id = :id", [':id' => $newest['id']]),
            'state_hash_mismatch' => $db->query("UPDATE documents SET title = 'Edited title' WHERE id = :id", [':id' => $documentId]),
            'snapshot_mismatch' => $db->query("UPDATE document_logs SET document_snapshot = JSON_SET(document_snapshot, '$.title', 'Forged') WHERE id = :id", [':id' => $middle['id']]),
        };
    }

    // Change fields of an entry and recompute its block hash from the new values
    private function rehash(array $log, array $changes): void
    {
        $log = array_merge($log, $changes);
        $hash = \App\Modules\Integrity\HashChainService::chainHash(
            (int) $log['document_id'],
            $log['user_id'] !== null ? (int) $log['user_id'] : null,
            $log['action'],
            \App\Modules\Integrity\HashChainService::logTimestamp($log['created_at']),
            (string) $log['previous_hash'],
            (string) $log['document_state_hash'],
            (string) ($log['remarks'] ?? ''),
            (string) $log['signature']
        );
        Database::getInstance()->query('UPDATE document_logs SET remarks = :r, signature = :s, hash = :h WHERE id = :id', [':r' => $log['remarks'], ':s' => $log['signature'], ':h' => $hash, ':id' => $log['id']]);
    }

    // A database name that differs from the configured one is refused before anything is read or written
    public function test_refuses_a_database_name_mismatch(): void
    {
        $this->unsealedDocuments();
        $before = $this->sealState();

        [$exit, , $stderr] = $this->runScript(['--database=deped_dts']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('does not match the database configured', $stderr);
        $this->assertSame($before, $this->sealState());
    }
}
