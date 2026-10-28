<?php

namespace Tests\Unit;

use App\Core\Database;
use App\Research\LiveRunCleanup;
use InvalidArgumentException;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * Cleanup scoping and refusals of the user-initiated live concurrency run (scripts/manual/live-concurrency.php).
 * Everything here runs against the test database; the script itself is only started in ways that refuse.
 */
class LiveRunCleanupTest extends TestCase
{
    private const RUN_ID = 'LIVE20261028120000';

    // Count document rows with the given id
    private function documentExists(int $id): bool
    {
        return (int) Database::getInstance()->query('SELECT COUNT(*) c FROM documents WHERE id = :id', [':id' => $id])->fetch()['c'] === 1;
    }

    // Run the live script as a subprocess and return [exit code, combined output]
    private function runScript(array $args, array $env): array
    {
        $cmd = array_merge([PHP_BINARY, BASE_PATH . '/scripts/manual/live-concurrency.php'], $args);
        $process = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, BASE_PATH, $env);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    // Only collected ids whose title carries this run's prefix are deleted; everything else survives
    public function test_cleanup_only_deletes_collected_ids_with_run_prefix(): void
    {
        $prefix = 'CONC-LIVE-' . self::RUN_ID . '-';
        $ours = Fixtures::submit(['title' => $prefix . '0'])['id'];
        $oursNotCollected = Fixtures::submit(['title' => $prefix . '1'])['id'];
        $otherTitle = Fixtures::submit(['title' => 'Real guest document'])['id'];
        $otherRun = Fixtures::submit(['title' => 'CONC-LIVE-LIVE20261028120001-2'])['id'];
        $longerRun = Fixtures::submit(['title' => 'CONC-LIVE-' . self::RUN_ID . '9-3'])['id'];

        $deleted = LiveRunCleanup::delete(Database::getInstance()->getConnection(), self::RUN_ID, [$ours, $otherTitle, $otherRun, $longerRun]);

        // Exactly the one row that is both collected and prefixed is gone
        $this->assertSame(1, $deleted);
        $this->assertFalse($this->documentExists($ours));
        $this->assertTrue($this->documentExists($oursNotCollected), 'A document that was not collected was deleted.');
        $this->assertTrue($this->documentExists($otherTitle), 'A collected id with another title was deleted.');
        $this->assertTrue($this->documentExists($otherRun), 'A collected id with another run prefix was deleted.');
        $this->assertTrue($this->documentExists($longerRun), 'A run id that merely starts with this one was deleted.');
    }

    // Deleting a run document also removes its log chain
    public function test_cleanup_cascades_logs(): void
    {
        $id = Fixtures::submit(['title' => 'CONC-LIVE-' . self::RUN_ID . '-0'])['id'];
        $db = Database::getInstance();
        $this->assertSame(1, (int) $db->query('SELECT COUNT(*) c FROM document_logs WHERE document_id = :id', [':id' => $id])->fetch()['c']);

        LiveRunCleanup::delete($db->getConnection(), self::RUN_ID, [$id]);

        $this->assertSame(0, (int) $db->query('SELECT COUNT(*) c FROM document_logs WHERE document_id = :id', [':id' => $id])->fetch()['c']);
    }

    // A run id that is not LIVE plus 14 digits (for example one carrying LIKE wildcards) is rejected before any query
    public function test_cleanup_rejects_malformed_run_id(): void
    {
        $id = Fixtures::submit(['title' => 'CONC-LIVE-anything'])['id'];

        // Each malformed id must throw and leave the row in place
        foreach (['LIVE%', 'LIVE_0261028120000', 'LIVE2026102812000', '', 'LIVE20261028120000-'] as $runId) {
            try {
                LiveRunCleanup::delete(Database::getInstance()->getConnection(), $runId, [$id]);
                $this->fail("Run id '{$runId}' was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertTrue($this->documentExists($id));
    }

    // Checkpoint files written after the run started are reported; older ones are not
    public function test_reports_checkpoints_written_after_run_start(): void
    {
        $dir = sys_get_temp_dir() . '/dts-live-cp-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("{$dir}/checkpoint-old.json", '{}');
        touch("{$dir}/checkpoint-old.json", time() - 3600);
        file_put_contents("{$dir}/checkpoint-new.json", '{}');

        try {
            $found = LiveRunCleanup::checkpointsWrittenSince($dir, time() - 60);
        } finally {
            // Remove the scratch directory whatever the assertion outcome
            array_map('unlink', glob("{$dir}/*"));
            rmdir($dir);
        }

        $this->assertSame(['checkpoint-new.json'], array_map('basename', $found));
    }

    // Cleanup ignores SIGINT/SIGTERM before it stops workers or deletes anything, so a second Ctrl-C cannot abort it
    public function test_finish_ignores_interrupts_before_deleting(): void
    {
        $pdo = Database::getInstance()->getConnection();
        $id = Fixtures::submit(['title' => 'CONC-LIVE-' . self::RUN_ID . '-0'])['id'];
        pcntl_signal(SIGINT, SIG_DFL);
        pcntl_signal(SIGTERM, SIG_DFL);

        try {
            // Capture the handlers and the row's presence at the moment workers are stopped (before any delete)
            $seen = null;
            LiveRunCleanup::finish($pdo, self::RUN_ID, [$id], $id - 1, function () use (&$seen, $id): int {
                $seen = [pcntl_signal_get_handler(SIGINT), pcntl_signal_get_handler(SIGTERM), $this->documentExists($id)];
                return 0;
            }, fn(float $s) => null);
        } finally {
            // Give PHPUnit its default signal behaviour back
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGTERM, SIG_DFL);
        }

        $this->assertSame([SIG_IGN, SIG_IGN, true], $seen);
        $this->assertFalse($this->documentExists($id));
    }

    // The second sweep always runs after the settle delay and removes a straggler committed during it
    public function test_finish_always_resweeps_after_settle_delay(): void
    {
        $pdo = Database::getInstance()->getConnection();
        $before = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM documents')->fetchColumn();
        $reported = Fixtures::submit(['title' => 'CONC-LIVE-' . self::RUN_ID . '-0'])['id'];
        $unrelated = Fixtures::submit(['title' => 'Real guest document'])['id'];

        try {
            // A worker killed mid-POST: the server commits its row while cleanup is waiting
            $straggler = null;
            $result = LiveRunCleanup::finish($pdo, self::RUN_ID, [$reported], $before, null, function (float $s) use (&$straggler): void {
                $this->assertSame(3.0, $s);
                $straggler = Fixtures::submit(['title' => 'CONC-LIVE-' . self::RUN_ID . '-1'])['id'];
            });
        } finally {
            // finish() leaves interrupts ignored; restore them for the rest of the suite
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGTERM, SIG_DFL);
        }

        $this->assertSame(['deleted' => 2, 'remaining' => 0, 'killed' => 0], $result);
        $this->assertFalse($this->documentExists($reported));
        $this->assertFalse($this->documentExists($straggler));
        $this->assertTrue($this->documentExists($unrelated));
    }

    // Without --confirm-live the script stops before reading any configuration
    public function test_script_refuses_without_confirm_flag(): void
    {
        // APP_ENV stays "testing" as a second safety net; the confirm check must fire first
        [$code, $output] = $this->runScript(['--base-url=http://127.0.0.1:9'], array_merge(getenv(), ['APP_ENV' => 'testing']));

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('--confirm-live', $output);
        $this->assertStringNotContainsString('Run id', $output);
    }

    // Under APP_ENV=testing the script refuses and points at the automated suite
    public function test_script_refuses_under_testing_env(): void
    {
        [$code, $output] = $this->runScript(['--base-url=http://127.0.0.1:9', '--confirm-live'], array_merge(getenv(), ['APP_ENV' => 'testing']));

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('composer test:concurrency', $output);
        $this->assertStringNotContainsString('Run id', $output);
    }
}
