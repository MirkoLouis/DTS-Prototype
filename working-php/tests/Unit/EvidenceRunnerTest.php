<?php

namespace Tests\Unit;

use App\Research\EvidenceRunner;
use PHPUnit\Framework\TestCase;

/**
 * `composer run evidence` runs every thesis step into its own subfolder of one evidence folder.
 */
class EvidenceRunnerTest extends TestCase
{
    private string $root;

    // Calls the fake process runner received: [step folder, env, parent existed]
    private array $calls = [];

    // A throwaway results root per test
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/evidence-test-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
        $this->calls = [];
    }

    // Remove the throwaway results root, deepest entries first
    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    // Regex for one README index row: folder, step, run, command, exit code, duration, then the test counts
    private static function rowPattern(string $step, int $run, int $exit, string $counts = '7 \\| 6 \\| 1 \\| 0'): string
    {
        $folder = "{$step}-run-{$run}";

        return '/^\\| \\[' . $folder . '\\]\\(' . $folder . '\\/\\) \\| ' . $step . ' \\| ' . $run . ' \\| `[^`]+` \\| ' . $exit . ' \\| [0-9.]+ \\| ' . $counts . ' \\|$/m';
    }

    /**
     * A runner whose fake processes write a summary.csv and fail only for the named step folder.
     */
    private function runner(?string $failingFolder = null): EvidenceRunner
    {
        $fake = function (array $argv, array $env) use ($failingFolder): int {
            // Record the call and whether the evidence folder already existed
            $dir = $env['DTS_RESULTS_DIR'];
            $this->calls[] = ['folder' => basename($dir), 'env' => $env, 'argv' => $argv, 'parent_existed' => is_dir(dirname($dir))];

            // Write a summary like a real PHPUnit run would
            mkdir($dir, 0775, true);
            file_put_contents($dir . '/summary.csv', "run_id,suite,tests,passed,failed,errored,skipped,incomplete,risky,duration_s\nx,all,7,6,1,0,0,0,0,1.5\n");

            return basename($dir) === $failingFolder ? 1 : 0;
        };

        return new EvidenceRunner($this->root, $fake, new \DateTimeImmutable('2026-11-12 20:15:00'));
    }

    // Every step and repeat gets its own subfolder, in order, under a folder that exists first
    public function test_runs_every_step_into_its_own_subfolder(): void
    {
        $result = $this->runner()->run(2);

        $this->assertSame(
            ['full-suite-run-1', 'workflow-run-1', 'integrity-run-1', 'integrity-run-2', 'concurrency-run-1'],
            array_column($this->calls, 'folder')
        );
        foreach ($this->calls as $call) {
            $this->assertSame($result['dir'] . '/' . $call['folder'], $call['env']['DTS_RESULTS_DIR']);
            $this->assertTrue($call['parent_existed']);
        }
        $this->assertSame('2', $this->calls[4]['env']['CONCURRENCY_RUNS']);
        $this->assertContains('thesis-integrity', $this->calls[2]['argv']);
        $this->assertSame($this->root . '/20261112-201500-evidence', $result['dir']);
        $this->assertSame(0, $result['failed']);
    }

    // The README index has one row per run with its exit code and test counts
    public function test_readme_indexes_every_run_with_exit_code_and_counts(): void
    {
        $result = $this->runner()->run(1);
        $readme = file_get_contents($result['dir'] . '/README.md');

        foreach (['full-suite', 'workflow', 'integrity', 'concurrency'] as $step) {
            $this->assertMatchesRegularExpression(self::rowPattern($step, 1, 0), $readme);
        }
        $this->assertFileExists($result['dir'] . '/run.json');
    }

    // A failing step is recorded and the remaining steps still run
    public function test_failed_step_does_not_stop_the_run(): void
    {
        $result = $this->runner('integrity-run-1')->run(2);

        $this->assertCount(5, $this->calls);
        $this->assertSame(1, $result['failed']);
        $readme = file_get_contents($result['dir'] . '/README.md');
        $this->assertMatchesRegularExpression(self::rowPattern('integrity', 1, 1), $readme);
        $this->assertMatchesRegularExpression(self::rowPattern('integrity', 2, 0), $readme);
    }

    // An existing evidence folder is never reused
    public function test_existing_folder_is_never_reused(): void
    {
        mkdir($this->root . '/20261112-201500-evidence');

        $result = $this->runner()->run(1);

        $this->assertSame($this->root . '/20261112-201500-evidence-2', $result['dir']);
    }

    // The planned steps can be listed without running anything
    public function test_steps_lists_commands_without_running(): void
    {
        $steps = EvidenceRunner::steps(3);

        $this->assertSame(['full-suite', 'workflow', 'integrity', 'concurrency'], array_column($steps, 'name'));
        $this->assertSame([1, 1, 3, 1], array_column($steps, 'repeat'));
        $this->assertSame([], $this->calls);
    }
}
