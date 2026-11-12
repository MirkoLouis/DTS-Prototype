<?php

namespace App\Research;

/**
 * Runs the thesis evidence set (full suite, workflow, integrity, concurrency) into one evidence folder:
 * storage/test-results/<YYYYMMDD-HHMMSS>-evidence/<step>-run-<N>/, plus a README.md index and run.json.
 *
 * Each step is a separate process whose DTS_RESULTS_DIR points at its own subfolder, so ResultWriter
 * writes every run's CSVs there unchanged.
 */
class EvidenceRunner
{
    // Inherited settings that change test results; recorded so the index states what actually ran
    private const RECORDED_SETTINGS = [
        'TEST_BASE_URL' => 'unset (the tests start the built-in PHP server)',
        'CONCURRENCY_WORKERS' => 'unset (test default)',
    ];

    /** @var callable(array $argv, array $env): int */
    private $runProcess;

    private \DateTimeImmutable $now;

    /**
     * @param callable(array $argv, array $env): int $runProcess runs one command and returns its exit code
     */
    public function __construct(private string $resultsRoot, callable $runProcess, ?\DateTimeImmutable $now = null)
    {
        $this->runProcess = $runProcess;
        $this->now = $now ?? ResultWriter::now();
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
            // Every default suite (unit, workflow, http, security); concurrency and benchmark are excluded by phpunit.xml
            ['name' => 'full-suite', 'argv' => $phpunit, 'env' => [], 'repeat' => 1],
            ['name' => 'workflow', 'argv' => [...$phpunit, '--group', 'thesis-workflow'], 'env' => [], 'repeat' => 1],
            ['name' => 'integrity', 'argv' => [...$phpunit, '--group', 'thesis-integrity'], 'env' => [], 'repeat' => $runs],
            // The concurrency tests repeat internally, so one process runs them $runs times
            ['name' => 'concurrency', 'argv' => [...$phpunit, '--testsuite', 'concurrency', '--group', 'concurrency'], 'env' => ['CONCURRENCY_RUNS' => (string) $runs], 'repeat' => 1],
        ];
    }

    /**
     * The command as shown in the index and by --list: environment prefix, then `php vendor/bin/phpunit …`.
     */
    public static function commandText(array $step): string
    {
        $prefix = '';

        // Each extra variable is shown as KEY=value before the command
        foreach ($step['env'] as $key => $value) {
            $prefix .= "{$key}={$value} ";
        }

        return $prefix . 'php ' . implode(' ', array_slice($step['argv'], 1));
    }

    /**
     * Run every step and repeat, keep the index current after each run, and point `latest` at the folder.
     *
     * @param array<string, string> $environment the environment the children inherit (to record result-changing settings)
     * @return array{dir: string, failed: int}
     */
    public function run(int $runs, array $environment = []): array
    {
        $startedAt = $this->now->format('c');
        $dir = $this->createFolder();
        $settings = self::effectiveSettings($runs, $environment);
        $rows = [];
        $failed = 0;

        // Every repeat of every step runs, even after a failure, so the set is complete
        foreach (self::steps($runs) as $step) {
            // One process per repeat, each in its own subfolder
            for ($run = 1; $run <= $step['repeat']; $run++) {
                $folder = "{$step['name']}-run-{$run}";
                $env = array_merge($step['env'], ['DTS_RESULTS_DIR' => $dir . '/' . $folder]);

                $start = hrtime(true);
                $exit = ($this->runProcess)($step['argv'], $env);
                $seconds = (hrtime(true) - $start) / 1e9;

                $failed += $exit === 0 ? 0 : 1;
                $rows[] = [$folder, $step['name'], $run, self::commandText($step), $exit, $seconds, self::summaryCounts($dir . '/' . $folder)];

                // An interrupted set still has an index, marked in progress
                $this->writeReadme($dir, $startedAt, $settings, $rows, $failed, false);
            }
        }

        // Final index and machine/commit record, then make the folder the newest result
        $this->writeReadme($dir, $startedAt, $settings, $rows, $failed, true);
        ResultWriter::writeRunJson(['script' => 'evidence', 'started_at' => $startedAt, 'settings' => $settings, 'failed_runs' => $failed], $dir);
        ResultWriter::pointLatestAt($this->resultsRoot, basename($dir));

        return ['dir' => $dir, 'failed' => $failed];
    }

    /**
     * The settings that shape this evidence set: the repeat counts it sets and the inherited ones it records.
     *
     * @return array<string, string>
     */
    private static function effectiveSettings(int $runs, array $environment): array
    {
        $settings = ['EVIDENCE_RUNS' => (string) $runs, 'CONCURRENCY_RUNS' => (string) $runs];

        // An inherited value is recorded as is; a missing or empty one as the test default
        foreach (self::RECORDED_SETTINGS as $name => $default) {
            $value = (string) ($environment[$name] ?? '');
            $settings[$name] = $value !== '' ? $value : $default;
        }

        return $settings;
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
     * Tests, passed, failed, errored and skipped from the run's summary.csv row "all";
     * nulls when the file is missing, unreadable or malformed (e.g. a run killed mid-write).
     *
     * @return array{0: ?int, 1: ?int, 2: ?int, 3: ?int, 4: ?int}
     */
    private static function summaryCounts(string $runDir): array
    {
        $empty = [null, null, null, null, null];
        $handle = is_file($runDir . '/summary.csv') ? @fopen($runDir . '/summary.csv', 'r') : false;

        // No readable summary: the run died before PHPUnit finished
        if ($handle === false) {
            return $empty;
        }

        $header = fgetcsv($handle, null, ',', '"', '\\') ?: [];
        $counts = $empty;

        // Find the "all" row by header name, skipping rows whose width does not match the header
        while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            if (count($row) !== count($header)) {
                continue;
            }
            $record = array_combine($header, $row);

            // Only the "all" row with every count column present is used
            if (($record['suite'] ?? '') === 'all' && isset($record['tests'], $record['passed'], $record['failed'], $record['errored'], $record['skipped'])) {
                $counts = [(int) $record['tests'], (int) $record['passed'], (int) $record['failed'], (int) $record['errored'], (int) $record['skipped']];
                break;
            }
        }
        fclose($handle);

        return $counts;
    }

    /**
     * Write README.md: status, settings, machine, and one table row per finished run.
     */
    private function writeReadme(string $dir, string $startedAt, array $settings, array $rows, int $failed, bool $complete): void
    {
        $settingLines = [];

        // One bullet per setting: KEY=value when set, otherwise what the tests fall back to
        foreach ($settings as $name => $value) {
            $isDefault = in_array($value, self::RECORDED_SETTINGS, true);
            $settingLines[] = $isDefault ? "- `{$name}` {$value}" : "- `{$name}={$value}`";
        }

        // Status line: in progress, all passed, or how many runs failed
        if (!$complete) {
            $status = '**In progress** (or interrupted): ' . count($rows) . ' run(s) finished so far.';
        } elseif ($failed === 0) {
            $status = 'Complete. All runs exited with code 0.';
        } else {
            $status = "Complete. **{$failed} run(s) exited with a non-zero code.**";
        }

        $lines = [
            '# Evidence run ' . basename($dir),
            '',
            $status,
            '',
            "Command: `EVIDENCE_RUNS={$settings['EVIDENCE_RUNS']} composer run evidence`. Started {$startedAt}" . ($complete ? ', finished ' . ResultWriter::now()->format('c') : '') . '.',
            '',
            'Machine: commit `' . ResultWriter::gitDescribe() . '`, PHP ' . PHP_VERSION . ', ' . ResultWriter::cpuModel() . ', ' . ResultWriter::ramGb() . ' GB RAM.',
            '',
            'Settings:',
            ...$settingLines,
            '',
            'Each folder is one PHPUnit run with its own `run.json`, `summary.csv`, `test-outcomes.csv` and evidence CSVs. "Duration" is the wall time of the whole process (including the test-database rebuild); `summary.csv` `duration_s` is the time spent in tests.',
            '',
            '| Folder | Step | Run | Command | Exit | Duration (s) | Tests | Passed | Failed | Errored | Skipped |',
            '| :--- | :--- | ---: | :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
        ];

        // One row per run; missing counts (no usable summary.csv) stay blank
        foreach ($rows as [$folder, $step, $run, $command, $exit, $seconds, $counts]) {
            $cells = array_map(fn ($count) => $count === null ? '' : (string) $count, $counts);
            $lines[] = sprintf('| [%s](%s/) | %s | %d | `%s` | %d | %.1f | %s |', $folder, $folder, $step, $run, $command, $exit, $seconds, implode(' | ', $cells));
        }

        file_put_contents($dir . '/README.md', implode("\n", $lines) . "\n");
    }
}
