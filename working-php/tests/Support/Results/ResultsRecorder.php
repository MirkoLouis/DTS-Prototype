<?php

namespace Tests\Support\Results;

use App\Research\ResultWriter;

/**
 * Collects one record per test and writes test-outcomes.csv, summary.csv and run.json when the run ends.
 * Only the process that created the recorder (the parent PHPUnit process) ever writes.
 */
class ResultsRecorder
{
    // Statuses in the order they are counted in summary.csv
    private const STATUSES = ['passed', 'failed', 'errored', 'skipped', 'incomplete', 'risky'];

    // Higher rank wins when one test reports several outcomes
    private const RANK = ['passed' => 0, 'risky' => 1, 'incomplete' => 2, 'skipped' => 3, 'failed' => 4, 'errored' => 5];

    private int $ownerPid;
    private string $startedAt;
    private float $startedHr;

    /** @var array<string, array> in-flight and finished tests keyed by PHPUnit test id */
    private array $tests = [];

    /** @var list<array> finished test rows in execution order */
    private array $rows = [];

    public function __construct()
    {
        // Remember the owning process and the start time
        $this->ownerPid = getmypid();
        $this->startedAt = date('c');
        $this->startedHr = microtime(true);
    }

    /**
     * A test started: remember its start time and identity.
     */
    public function prepared(string $id, string $class, string $method, string $dataSet, string $file, float $seconds): void
    {
        $this->tests[$id] = [
            'class' => $class,
            'test' => $method,
            'data_set' => $dataSet,
            'suite' => $this->suiteOf($file),
            'status' => 'passed',
            'message' => '',
            'start' => $seconds,
            'assertions' => 0,
        ];
    }

    /**
     * A test reported an outcome; the most severe one is kept.
     */
    public function outcome(string $id, string $status, string $message = ''): void
    {
        // Ignore events for tests that were never prepared (hook failures)
        if (!isset($this->tests[$id])) {
            return;
        }
        if (self::RANK[$status] >= self::RANK[$this->tests[$id]['status']]) {
            $this->tests[$id]['status'] = $status;
            $this->tests[$id]['message'] = strtok(trim($message), "\n") ?: '';
        }
    }

    /**
     * A test finished: freeze its row.
     */
    public function finished(string $id, int $assertions, float $seconds): void
    {
        if (!isset($this->tests[$id])) {
            return;
        }
        $t = $this->tests[$id];
        unset($this->tests[$id]);
        $t['assertions'] = $assertions;
        $t['timestamp'] = date('c');
        $t['duration_ms'] = round(($seconds - $t['start']) * 1000, 3);
        $this->rows[] = $t;
    }

    /**
     * The run is over: write the three files (parent process only).
     */
    public function write(): void
    {
        // A forked child that somehow got here must not write
        if (getmypid() !== $this->ownerPid) {
            return;
        }
        $dir = ResultWriter::dir();
        $runId = basename($dir);

        // test-outcomes.csv: one row per test
        $out = fopen($dir . '/test-outcomes.csv', 'w');
        fputcsv($out, ['run_id', 'suite', 'class', 'test', 'data_set', 'status', 'duration_ms', 'assertions', 'message', 'timestamp'], ',', '"', '\\');
        $stats = [];
        foreach ($this->rows as $r) {
            fputcsv($out, [$runId, $r['suite'], $r['class'], $r['test'], $r['data_set'], $r['status'], $r['duration_ms'], $r['assertions'], $r['message'], $r['timestamp']], ',', '"', '\\');

            // Per-suite counters, plus the overall row
            foreach ([$r['suite'], 'all'] as $key) {
                $stats[$key] ??= array_fill_keys(self::STATUSES, 0) + ['tests' => 0, 'ms' => 0.0];
                $stats[$key]['tests']++;
                $stats[$key][$r['status']]++;
                $stats[$key]['ms'] += $r['duration_ms'];
            }
        }
        fclose($out);

        // summary.csv: one row per suite, then "all"
        $all = $stats['all'] ?? array_fill_keys(self::STATUSES, 0) + ['tests' => 0, 'ms' => 0.0];
        unset($stats['all']);
        ksort($stats);
        $stats['all'] = $all;
        $out = fopen($dir . '/summary.csv', 'w');
        fputcsv($out, ['run_id', 'suite', 'tests', ...self::STATUSES, 'duration_s'], ',', '"', '\\');
        foreach ($stats as $suite => $s) {
            fputcsv($out, [$runId, $suite, $s['tests'], ...array_map(fn($k) => $s[$k], self::STATUSES), round($s['ms'] / 1000, 3)], ',', '"', '\\');
        }
        fclose($out);

        // run.json: provenance of the run
        ResultWriter::writeRunJson([
            'started_at' => $this->startedAt,
            'finished_at' => date('c'),
            'duration_s' => round(microtime(true) - $this->startedHr, 3),
        ]);
    }

    /**
     * Suite name = first directory under tests/ (unit, workflow, http, security, concurrency, benchmark).
     */
    private function suiteOf(string $file): string
    {
        // Path relative to the project's tests/ folder; anything else is "other"
        $prefix = BASE_PATH . '/tests/';
        if (!str_starts_with($file, $prefix)) {
            return 'other';
        }

        return strtolower(explode('/', substr($file, strlen($prefix)))[0]);
    }
}
