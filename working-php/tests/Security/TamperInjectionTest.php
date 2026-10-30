<?php

namespace Tests\Security;

use App\Common\Database\Database;
use App\Integrity\DigitalSignature;
use App\Jobs\ChainCheckpointJob;
use App\Jobs\IntegrityCheckJob;
use App\Models\User;
use App\Policies\DocumentPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Fixtures;
use App\Research\ResultWriter;
use Tests\Support\Tamper;
use Tests\Support\ThreatActor;
use Tests\TestCase;

/**
 * Tamper-injection matrix: direct-database tactics against a completed lifecycle document.
 *
 * For each tactic it records whether the pre-action integrity check (DocumentPolicy::process) blocks and freezes
 * the document, and whether a fresh IntegrityCheckJob audit flags the document, one of its log
 * entries or the checkpoint. A mismatch on a non-null expectation is a finding and fails the case;
 * null expectations are observe-only. Rows go to storage/test-results/tamper-matrix.csv.
 */
class TamperInjectionTest extends TestCase
{
    private const CSV = 'tamper-matrix.csv';
    private const ROUTE = ['Cash Unit', 'Personnel Unit'];

    // One id for every row written by this PHPUnit process, so repeated runs can be told apart
    private static ?string $runId = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Remove test checkpoints from earlier cases; their heads refer to documents of a dropped database
        foreach (glob(ChainCheckpointJob::directory() . '/checkpoint-*.json') ?: [] as $file) {
            @unlink($file);
        }

        // The real audit runs in a fresh runner.php process, so start with an empty key-history cache
        DigitalSignature::clearKeyCache();
    }

    /**
     * tactic => [tactic, layer, expected_guard, expected_audit, write_checkpoint]
     */
    public static function tactics(): array
    {
        $rows = [
            ['edit_title', 'documents', true, true, true],
            ['edit_guest_email', 'documents', true, true, true],
            ['edit_finalized_route_skip_department', 'documents', true, true, true],
            ['corrupt_script', 'documents', true, true, true],
            ['edit_status_only', 'documents', false, false, true],
            ['edit_current_step', 'documents', false, false, true],
            ['edit_log_remarks', 'document_logs', false, true, true],
            ['edit_log_action', 'document_logs', false, true, true],
            ['edit_log_created_at', 'document_logs', false, true, true],
            ['edit_log_user_id', 'document_logs', false, true, true],
            ['edit_newest_log_state_hash', 'document_logs', true, true, true],
            ['delete_middle_log', 'document_logs', null, true, true],
            ['swap_two_log_payloads', 'document_logs', null, true, true],
            ['delete_tail_log_after_checkpoint', 'document_logs', null, true, true],
            ['delete_tail_log_without_checkpoint', 'document_logs', null, false, false],
            ['append_forged_log_recomputed_hash_bad_signature', 'documents+document_logs', null, true, true],
            ['append_log_signed_with_injected_attacker_key', 'documents+document_logs+user_public_key_histories', null, null, true],
            ['rewrite_whole_chain_with_attacker_keys', 'documents+document_logs+user_public_key_histories', null, null, true],
        ];

        // Key each data set by its tactic name for readable PHPUnit output
        return array_combine(array_column($rows, 0), $rows);
    }

    // Apply one tactic, observe both detection layers, record the row, then check non-null expectations
    #[DataProvider('tactics')]
    public function test_tactic(string $tactic, string $layer, ?bool $expectedGuard, ?bool $expectedAudit, bool $writeCheckpoint): void
    {
        // 1. A fresh, fully signed, completed lifecycle document
        $document = Fixtures::submit();
        Fixtures::advanceTo($document['id'], 'completed', self::ROUTE);

        // 2. Signed chain-head checkpoint, unless the case models deletion before any checkpoint
        if ($writeCheckpoint) {
            (new ChainCheckpointJob())->create();
        }

        // 3. The tactic, with raw PDO
        Tamper::{self::method($tactic)}(Database::getInstance()->getConnection(), $document['id']);

        // 4 and 5. Pre-action integrity check first (as on the next user action), then a fresh audit
        $detectedGuard = $this->guardDetects($document['id']);
        [$detectedAudit, $channels] = $this->auditDetects($document['id'], $document['tracking_code']);

        $this->record($tactic, $layer, $expectedGuard, $detectedGuard, $expectedAudit, $detectedAudit);

        // Every mismatch on a non-null expectation is a finding
        $findings = [];
        if ($expectedGuard !== null && $expectedGuard !== $detectedGuard) {
            $findings[] = sprintf('guard expected %s, observed %s', self::text($expectedGuard), self::text($detectedGuard));
        }
        if ($expectedAudit !== null && $expectedAudit !== $detectedAudit) {
            $findings[] = sprintf('audit expected %s, observed %s (channels: %s)', self::text($expectedAudit), self::text($detectedAudit), implode(',', $channels) ?: 'none');
        }
        $this->assertSame([], $findings, "FINDING [{$tactic}]: " . implode('; ', $findings));
    }

    // Control row: unmodified chains must audit at 100% with no guard or checkpoint alarm (false-positive check)
    public function test_untouched_chains_report_100_percent(): void
    {
        // A mix of lifecycle states, all produced by the real workflow
        $documents = [];
        foreach (['completed', 'completed', 'ready_for_release', 'processing', 'in_transit', 'declined'] as $status) {
            $document = Fixtures::submit();
            Fixtures::advanceTo($document['id'], $status, self::ROUTE);
            $documents[] = $document;
        }
        $documents[] = Fixtures::submit();
        (new ChainCheckpointJob())->create();

        // The guard must let the admin act on every document and freeze none
        $guardAlarms = 0;
        foreach ($documents as $document) {
            $guardAlarms += $this->guardDetects($document['id']) ? 1 : 0;
        }

        $results = $this->runAudit();
        $flagged = 0;
        foreach ($documents as $document) {
            $flagged += $this->auditDetects($document['id'], $document['tracking_code'], $results)[0] ? 1 : 0;
        }

        $this->record('none_untouched_control', 'none', false, $guardAlarms > 0, false, $flagged > 0);

        // Whole-system figures the System Health page shows
        $this->assertSame(0, $guardAlarms, 'Pre-action integrity check flagged an untouched document');
        $this->assertEquals(100.0, $results['verified_percentage']);
        $this->assertSame(0, $results['invalid_logs']);
        $this->assertSame(0, $results['invalid_signatures']);
        $this->assertSame(0, $results['live_state_errors_count']);
        $this->assertTrue($results['checkpoint']['file_intact']);
        $this->assertSame(0, $results['checkpoint']['missing_heads']);
        $this->assertSame(0, $flagged);
    }

    // Guard detected = process() refuses the admin AND the document is now frozen
    private function guardDetects(int $documentId): bool
    {
        $row = $this->documentRow($documentId);
        $allowed = (new DocumentPolicy())->process(User::findById($this->fx['users']['admin']), $row);

        return !$allowed && $this->documentRow($documentId)['status'] === 'frozen';
    }

    /**
     * Audit detected = the document's live state, any of its log entries, or the checkpoint is flagged.
     *
     * @return array{0: bool, 1: string[]} detected flag and the channels that flagged it
     */
    private function auditDetects(int $documentId, string $trackingCode, ?array $results = null): array
    {
        $results ??= $this->runAudit();
        $channels = [];

        // Live state differs from the newest sealed state
        if (in_array($trackingCode, $results['mismatched_document_tracking_codes'], true)) {
            $channels[] = 'live_state';
        }

        // A surviving log entry of this document fails its chain hash or signature
        $logIds = Database::getInstance()->query('SELECT id FROM document_logs WHERE document_id = :id', [':id' => $documentId])->fetchAll(\PDO::FETCH_COLUMN);
        $badIds = array_map('intval', $results['mismatched_ids']);
        if (array_intersect(array_map('intval', $logIds), $badIds)) {
            $channels[] = 'log_entry';
        }

        // The checkpoint file is altered or a recorded chain head of this document is gone
        $checkpoint = $results['checkpoint'];
        if ($checkpoint !== null && !$checkpoint['file_intact']) {
            $channels[] = 'checkpoint_file';
        }
        if ($checkpoint !== null && in_array($documentId, $checkpoint['missing_document_ids'], true)) {
            $channels[] = 'checkpoint_head';
        }

        return [$channels !== [], $channels];
    }

    // Queue and run one audit exactly as the System Health page does, and return its results
    private function runAudit(): array
    {
        $db = Database::getInstance();
        $id = bin2hex(random_bytes(16));
        $db->query("INSERT INTO integrity_checks (id, user_id, status, progress, created_at, updated_at) VALUES (:id, :u, 'queued', 0, NOW(), NOW())", [
            ':id' => $id,
            ':u' => $this->fx['users']['admin'],
        ]);

        (new IntegrityCheckJob($id))->handle();

        // An audit that did not complete is a harness error, not a detection result
        $check = $db->query('SELECT status, results, error_message FROM integrity_checks WHERE id = :id', [':id' => $id])->fetch();
        $this->assertSame('completed', $check['status'], 'Audit failed: ' . (string) $check['error_message']);

        return json_decode($check['results'], true);
    }

    // Append one matrix row
    private function record(string $tactic, string $layer, ?bool $expectedGuard, bool $detectedGuard, ?bool $expectedAudit, bool $detectedAudit): void
    {
        ResultWriter::append(self::CSV, [
            'run_id' => self::runId(),
            'tactic' => $tactic,
            'threat_actor' => $layer === 'none' ? 'none' : ThreatActor::PRIVILEGED_INSIDER,
            'layer' => $layer,
            'expected_guard' => self::text($expectedGuard),
            'detected_guard' => self::text($detectedGuard),
            'expected_audit' => self::text($expectedAudit),
            'detected_audit' => self::text($detectedAudit),
            'run_at' => date('c'),
            'git_commit' => self::gitCommit(),
        ]);
    }

    // Fetch the live document row
    private function documentRow(int $documentId): array
    {
        return Database::getInstance()->query('SELECT * FROM documents WHERE id = :id', [':id' => $documentId])->fetch();
    }

    // Matrix tactic name (snake_case) to Tamper method name (camelCase); both tail deletions share one method
    private static function method(string $tactic): string
    {
        if (str_starts_with($tactic, 'delete_tail_log')) {
            return 'deleteTailLog';
        }

        return lcfirst(str_replace('_', '', ucwords($tactic, '_')));
    }

    // CSV text for a nullable boolean
    private static function text(?bool $value): string
    {
        return $value === null ? 'null' : ($value ? 'true' : 'false');
    }

    // Timestamp plus random suffix, created once per process
    private static function runId(): string
    {
        return self::$runId ??= date('Ymd\THis') . '-' . bin2hex(random_bytes(3));
    }

    // git describe of the working tree, marking uncommitted changes ('unknown' outside a repository)
    private static function gitCommit(): string
    {
        $commit = trim((string) @shell_exec('git -C ' . escapeshellarg(BASE_PATH) . ' describe --always --dirty 2>/dev/null'));

        return $commit !== '' ? $commit : 'unknown';
    }
}
