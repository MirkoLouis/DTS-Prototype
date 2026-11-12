<?php

// Usage: [EVIDENCE_RUNS=5] composer run evidence [-- --list]
// Runs the thesis evidence set into storage/test-results/<YYYYMMDD-HHMMSS>-evidence/ (see EvidenceRunner).
define('BASE_PATH', dirname(__DIR__));
require __DIR__ . '/../vendor/autoload.php';

use App\Research\EvidenceRunner;

// Repeats per step; must be a positive integer
$runsSetting = getenv('EVIDENCE_RUNS');
$runs = ($runsSetting === false || $runsSetting === '') ? 5 : filter_var($runsSetting, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($runs === false) {
    fwrite(STDERR, "EVIDENCE_RUNS must be a whole number of at least 1 (got \"{$runsSetting}\").\n");
    exit(2);
}

// Dry run: print the planned commands and stop
if (in_array('--list', array_slice($argv, 1), true)) {
    // One line per step: name, repeats, command
    foreach (EvidenceRunner::steps($runs) as $step) {
        printf("%-12s x%d  %s\n", $step['name'], $step['repeat'], EvidenceRunner::commandText($step));
    }
    exit(0);
}

/**
 * Run one command (argument array, no shell) in working-php with the inherited terminal and the given extra environment.
 */
$runProcess = function (array $argv, array $env): int {
    fwrite(STDOUT, "\n==> " . basename($env['DTS_RESULTS_DIR']) . "\n");
    $process = proc_open($argv, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, BASE_PATH, array_merge(getenv(), $env));

    // A process that could not start counts as a failed run
    if (!is_resource($process)) {
        return 1;
    }

    return proc_close($process);
};

// Children inherit this environment; the runner records the settings in it that change results
$result = (new EvidenceRunner(BASE_PATH . '/storage/test-results', $runProcess))->run($runs, getenv());

// Point at the index, and fail the command when any run failed
echo "\nEvidence folder: {$result['dir']}\nIndex: {$result['dir']}/README.md\n";
if ($result['failed'] > 0) {
    fwrite(STDERR, "{$result['failed']} run(s) failed; see the index.\n");
    exit(1);
}
