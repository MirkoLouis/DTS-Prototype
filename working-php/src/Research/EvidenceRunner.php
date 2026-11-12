<?php

namespace App\Research;

use App\Modules\Integrity\HashChainService;

/**
 * Runs the thesis evidence set (full suite, workflow, integrity, concurrency) into one evidence folder:
 * storage/test-results/<YYYYMMDD-HHMMSS>-evidence/<step>-run-<N>/, plus a README.md index and run.json.
 *
 * Each step is a separate process whose DTS_RESULTS_DIR points at its own subfolder, so ResultWriter
 * writes every run's CSVs there unchanged.
 */
class EvidenceRunner
{
    /** @var callable(array $argv, array $env): int */
    private $runProcess;

    private \DateTimeImmutable $now;

    /**
     * @param callable(array $argv, array $env): int $runProcess runs one command and returns its exit code
     */
    public function __construct(private string $resultsRoot, callable $runProcess, ?\DateTimeImmutable $now = null)
    {
        $this->runProcess = $runProcess;
        $this->now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone(HashChainService::APP_TIMEZONE));
    }

    /**
     * The evidence steps in run order: name, phpunit arguments, extra environment, and how often each runs.
     *
     * @return list<array{name: string, argv: list<string>, env: array<string, string>, repeat: int}>
     */
    public static function steps(int $runs): array
    {
        $phpunit = [PHP_BINARY, 'vendor/bin/phpunit'];

        return [
            ['name' => 'full-suite', 'argv' => [...$phpunit, '--testsuite', 'unit,workflow,http'], 'env' => [], 'repeat' => 1],
            ['name' => 'workflow', 'argv' => [...$phpunit, '--group', 'thesis-workflow'], 'env' => [], 'repeat' => 1],
            ['name' => 'integrity', 'argv' => [...$phpunit, '--group', 'thesis-integrity'], 'env' => [], 'repeat' => $runs],
            // The concurrency tests repeat internally, so one process runs them $runs times
            ['name' => 'concurrency', 'argv' => [...$phpunit, '--testsuite', 'concurrency', '--group', 'concurrency'], 'env' => ['CONCURRENCY_RUNS' => (string) $runs], 'repeat' => 1],
        ];
    }

    /**
     * Run every step and repeat, write the index, and point `latest` at the evidence folder.
     *
     * @return array{dir: string, failed: int}
     */
    public function run(int $runs): array
    {
        $startedAt = $this->now->format('c');
        $dir = $this->createFolder();
        $rows = [];
        $failed = 0;

        // Every repeat of every step runs, even after a failure, so the set is complete
        foreach (self::steps($runs) as $step) {
            for ($run = 1; $run <= $step['repeat']; $run++) {
                $folder = "{$step['name']}-run-{$run}";
                $env = array_merge($step['env'], ['DTS_RESULTS_DIR' => $dir . '/' . $folder]);

                $start = hrtime(true);
                $exit = ($this->runProcess)($step['argv'], $env);
                $seconds = (hrtime(true) - $start) / 1e9;

                $failed += $exit === 0 ? 0 : 1;
                $rows[] = [$folder, $step['name'], $run, self::commandText($step), $exit, $seconds, self::summaryCounts($dir . '/' . $folder)];
            }
        }

        // Index and machine/commit record, then make the folder the newest result
        $this->writeReadme($dir, $runs, $startedAt, $rows, $failed);
        ResultWriter::writeRunJson(['script' => 'evidence', 'evidence_runs' => $runs, 'started_at' => $startedAt, 'failed_runs' => $failed], $dir);
        ResultWriter::pointLatestAt($this->resultsRoot, basename($dir));

        return ['dir' => $dir, 'failed' => $failed];
    }

    /**
     * Create a new evidence folder; an existing name gets a -2, -3, … suffix instead of being reused.
     */
    private function createFolder(): string
    {
        $base = $this->resultsRoot . '/' . $this->now->format('Ymd-His') . '-evidence';
        $dir = $base;

        // Find the first free name
        for ($suffix = 2; file_exists($dir); $suffix++) {
            $dir = "{$base}-{$suffix}";
        }
        mkdir($dir, 0775, true);

        return $dir;
    }

    /**
     * The command as shown in the index: environment prefix, then `php vendor/bin/phpunit …`.
     */
    private static function commandText(array $step): string
    {
        $prefix = '';
        foreach ($step['env'] as $key => $value) {
            $prefix .= "{$key}={$value} ";
        }

        return $prefix . 'php ' . implode(' ', array_slice($step['argv'], 1));
    }

    /**
     * Tests, passed, failed and errored from the run's summary.csv row "all"; nulls when it is missing.
     *
     * @return array{0: ?int, 1: ?int, 2: ?int, 3: ?int}
     */
    private static function summaryCounts(string $runDir): array
    {
        $file = $runDir . '/summary.csv';
        $empty = [null, null, null, null];
        if (!is_file($file)) {
            return $empty;
        }

        // Find the "all" row by header name
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle, null, ',', '"', '\\');
        while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            $record = array_combine($header, $row);
            if (($record['suite'] ?? '') === 'all') {
                fclose($handle);
                return [(int) $record['tests'], (int) $record['passed'], (int) $record['failed'], (int) $record['errored']];
            }
        }
        fclose($handle);

        return $empty;
    }

    /**
     * Write README.md: what ran, when, and one table row per run.
     */
    private function writeReadme(string $dir, int $runs, string $startedAt, array $rows, int $failed): void
    {
        $finishedAt = (new \DateTimeImmutable('now', new \DateTimeZone(HashChainService::APP_TIMEZONE)))->format('c');
        $lines = [
            '# Evidence run ' . basename($dir),
            '',
            "Command: `EVIDENCE_RUNS={$runs} composer run evidence`. Started {$startedAt}, finished {$finishedAt}.",
            'Commit, PHP version, CPU and RAM are in `run.json`. Each folder below is one PHPUnit run with its own `run.json`, `summary.csv`, `test-outcomes.csv` and evidence CSVs.',
            '',
            $failed === 0 ? 'All runs exited with code 0.' : "**{$failed} run(s) exited with a non-zero code.**",
            '',
            '| Folder | Step | Run | Command | Exit | Duration (s) | Tests | Passed | Failed | Errored |',
            '| :--- | :--- | ---: | :--- | ---: | ---: | ---: | ---: | ---: | ---: |',
        ];

        // One row per run; missing counts (no summary.csv, e.g. a fatal error) stay blank
        foreach ($rows as [$folder, $step, $run, $command, $exit, $seconds, $counts]) {
            $cells = array_map(fn ($count) => $count === null ? '' : (string) $count, $counts);
            $lines[] = sprintf('| [%s](%s/) | %s | %d | `%s` | %d | %.1f | %s |', $folder, $folder, $step, $run, $command, $exit, $seconds, implode(' | ', $cells));
        }

        file_put_contents($dir . '/README.md', implode("\n", $lines) . "\n");
    }
}
