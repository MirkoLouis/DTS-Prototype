<?php

/**
 * User-initiated live concurrency run against the real web app (nginx + PHP-FPM) and the operational database.
 *
 * Usage:
 *   composer manual:live-concurrency -- --base-url=https://<lan-host>:8000 --confirm-live [--workers=100] [--insecure]
 *
 * Fires N simultaneous guest submissions (titles CONC-LIVE-{run_id}-{i}), verifies them in the database
 * the same way tests/Concurrency/GuestSubmissionConcurrencyTest does, appends a row to
 * storage/test-results/<run_id>/live-concurrency.csv, and ALWAYS deletes the documents it created (finally block,
 * plus a shutdown/signal fallback). It is not part of PHPUnit; the automated equivalent is composer test:concurrency.
 */

define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/vendor/autoload.php';
date_default_timezone_set(\App\Modules\Integrity\HashChainService::APP_TIMEZONE);

use App\Common\Database\Database;
use App\Research\Barrier;
use App\Research\BarrierWorkerException;
use App\Research\HttpSession;
use App\Research\LiveRunCleanup;
use App\Research\ResultWriter;

const USAGE = "Usage: composer manual:live-concurrency -- --base-url=https://<lan-host>:8000 --confirm-live [--workers=100] [--insecure]\n"
    . "For a large --workers, set COMPOSER_PROCESS_TIMEOUT=0 (or run php scripts/manual/live-concurrency.php directly) so Composer never kills the run before cleanup.\n";
const EXIT_REFUSED = 2;
const EXIT_FAILED = 1;

// Print a refusal to STDERR and stop
function refuse(string $message): never
{
    fwrite(STDERR, "Refusing to run: {$message}\n" . USAGE);
    exit(EXIT_REFUSED);
}

// Parse the command-line options
$options = ['base-url' => null, 'confirm-live' => false, 'workers' => 100, 'insecure' => false];
foreach (array_slice($argv, 1) as $arg) {
    // Each option is --name or --name=value
    if (str_starts_with($arg, '--base-url=')) {
        $options['base-url'] = substr($arg, 11);
    } elseif ($arg === '--confirm-live') {
        $options['confirm-live'] = true;
    } elseif (str_starts_with($arg, '--workers=')) {
        $options['workers'] = filter_var(substr($arg, 10), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    } elseif ($arg === '--insecure') {
        $options['insecure'] = true;
    } elseif ($arg === '--') {
        continue;
    } else {
        refuse("unknown argument '{$arg}'.");
    }
}

// Safety gate 1: the user must state that this writes to the live system
if (!$options['confirm-live']) {
    refuse("this submits real documents to the running web app and its operational database. Pass --confirm-live to confirm.");
}

// Safety gate 2: never under the testing environment (process env or the loaded .env); the automated suite covers that case
if (getenv('APP_ENV') === 'testing') {
    refuse("APP_ENV=testing is set. This script is for the live server only; for the test database run: composer test:concurrency");
}
$config = require BASE_PATH . '/src/Common/Config/config.php';
if ($config['app']['env'] === 'testing') {
    refuse(".env has APP_ENV=testing. This script is for the live server only; for the test database run: composer test:concurrency");
}

// Validate the remaining options
$baseUrl = rtrim((string) $options['base-url'], '/');
$scheme = parse_url($baseUrl, PHP_URL_SCHEME);
if ($baseUrl === '' || !in_array($scheme, ['http', 'https'], true) || !parse_url($baseUrl, PHP_URL_HOST)) {
    refuse("--base-url must be an http(s) URL such as https://192.168.1.10:8000.");
}
if ($options['workers'] === false) {
    refuse("--workers must be an integer from 1 to 1000.");
}
foreach (['pcntl_fork', 'posix_kill', 'curl_init'] as $function) {
    // Forking and HTTP need the pcntl, posix and curl extensions
    if (!function_exists($function)) {
        refuse("the PHP CLI lacks {$function}() (pcntl, posix and curl extensions are required).");
    }
}
$workers = (int) $options['workers'];
$verifyTls = !$options['insecure'];

// Open the operational database and pick the submission fields
$db = Database::getInstance();
$pdo = $db->getConnection();
$purposeId = $db->query('SELECT id FROM purposes WHERE is_official = 1 ORDER BY id LIMIT 1')->fetchColumn();
$department = $db->query("SELECT name FROM departments ORDER BY name = 'Records Unit' DESC, id LIMIT 1")->fetchColumn();
if ($purposeId === false || $department === false) {
    refuse("the database '{$config['database']['dbname']}' has no official purpose or no department to submit to.");
}

// Identify the run; ids above $maxIdBefore with this run's title prefix can only be this run's documents
$runId = 'LIVE' . date('YmdHis');
$prefix = LiveRunCleanup::titlePrefix($runId);
$runStart = microtime(true);
$maxIdBefore = (int) $db->query('SELECT COALESCE(MAX(id), 0) m FROM documents')->fetch()['m'];
$parentPid = getmypid();
echo "Run id:   {$runId}\n";
echo "Server:   {$baseUrl}" . ($verifyTls ? '' : ' (TLS verification disabled)') . "\n";
echo "Database: {$config['database']['dbname']}\n";
echo "Workers:  {$workers}\n\n";

// Shared state between the run, the cleanup and the CSV row
$results = [];
$runError = null;
$metrics = [
    'successes' => 0, 'distinct_codes' => 0, 'mismatches' => 0, 'documents_added' => 0,
    'submitted_log_faults' => 0, 'wall_seconds' => '', 'max_request_seconds' => '', 'skew_seconds' => '',
];
$cleanupDone = false;

// Cleanup: collect the run's ids, delete them (scoped by LiveRunCleanup), report and write the CSV row. Runs once.
$cleanup = function () use (&$cleanupDone, &$results, &$runError, &$metrics, $pdo, $runId, $prefix, $maxIdBefore, $runStart, $baseUrl, $workers, $parentPid): void {
    // Only the parent cleans up, and only once
    if ($cleanupDone || getmypid() !== $parentPid) {
        return;
    }
    $cleanupDone = true;

    // From here on a second Ctrl-C / SIGTERM must not abort cleanup halfway
    LiveRunCleanup::ignoreInterrupts();

    // Ids the workers reported in their /success redirects
    $collected = [];
    foreach ($results as $result) {
        if (!empty($result['document_id'])) {
            $collected[] = (int) $result['document_id'];
        }
    }

    // Ignore further interrupts, kill any worker still running, delete, then ALWAYS settle and re-sweep (LiveRunCleanup::finish)
    $deleted = 0;
    try {
        $outcome = LiveRunCleanup::finish($pdo, $runId, $collected, $maxIdBefore, fn(): int => Barrier::killActive(2.0));
        $deleted = $outcome['deleted'];
        $remaining = $outcome['remaining'];
        if ($outcome['killed'] > 0) {
            fwrite(STDERR, "Killed {$outcome['killed']} worker(s) that were still running when cleanup began.\n");
        }
    } catch (\Throwable $e) {
        $remaining = -1;
        fwrite(STDERR, "CLEANUP FAILED: " . $e->getMessage() . "\n");
    }
    echo "\nCleanup for run {$runId}: deleted {$deleted} document row(s) (their document_logs rows with them).\n";

    // Anything left must be removed by hand
    if ($remaining !== 0) {
        fwrite(STDERR, "WARNING: run documents may remain. Remove them with:\n"
            . "  DELETE FROM documents WHERE id > {$maxIdBefore} AND title LIKE '{$prefix}%';\n");
    }

    // A checkpoint written during the run records heads of documents that no longer exist
    $checkpoints = LiveRunCleanup::checkpointsWrittenSince(BASE_PATH . '/storage/checkpoints', $runStart);
    if ($checkpoints !== []) {
        fwrite(STDERR, "WARNING: these checkpoints were written after the run started and may list the deleted run documents' chain heads;\n"
            . "the next integrity audit will report those heads as missing:\n  " . implode("\n  ", $checkpoints) . "\n");
    }

    // Evidence row, written even when the run or verification failed; a write error must not lose the summary or exit code
    $notes = [];
    if ($runError) {
        $notes[] = 'run error: ' . substr($runError->getMessage(), 0, 200);
    }
    if (count($results) !== $workers) {
        $notes[] = 'partial results: ' . count($results) . " of {$workers} workers reported";
    }
    try {
        ResultWriter::append('live-concurrency.csv', [
            'timestamp' => date('c'),
            'test' => 'guest_submission_live',
            'run' => 1,
            'workers' => $workers,
            'successes' => $metrics['successes'],
            'distinct_codes' => $metrics['distinct_codes'],
            'mismatches' => $metrics['mismatches'],
            'documents_added' => $metrics['documents_added'],
            'submitted_log_faults' => $metrics['submitted_log_faults'],
            'wall_seconds' => $metrics['wall_seconds'],
            'max_request_seconds' => $metrics['max_request_seconds'],
            'server' => $baseUrl,
            'cache_warmed' => 'not cleared (live server cache untouched)',
            'skew_seconds' => $metrics['skew_seconds'],
            'notes' => implode('; ', $notes),
            'run_id' => $runId,
            'deleted_rows' => $deleted,
        ]);
        // Provenance of this script run, next to the CSV
        ResultWriter::writeRunJson(['script' => 'live-concurrency', 'live_run_id' => $runId, 'base_url' => $baseUrl]);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Could not write storage/test-results/<run_id>/live-concurrency.csv: " . $e->getMessage() . "\n");
    }
};

// Fallbacks: a fatal error or Ctrl-C / SIGTERM in the parent still cleans up; a forked child just dies
register_shutdown_function($cleanup);
pcntl_async_signals(true);
foreach ([SIGINT, SIGTERM] as $signal) {
    pcntl_signal($signal, function () use ($parentPid): void {
        // Children must not run shutdown code that could close the parent's inherited connection
        if (getmypid() !== $parentPid) {
            posix_kill(posix_getpid(), SIGKILL);
        }

        // Ignore repeats before exit() hands over to the shutdown cleanup
        LiveRunCleanup::ignoreInterrupts();
        fwrite(STDERR, "\nInterrupted; cleaning up.\n");
        exit(130);
    });
}

try {
    // Preflight: the form must load and carry a CSRF token before anything is submitted
    $probe = new HttpSession($baseUrl, $verifyTls);
    $page = $probe->get('/');
    if ($page['status'] !== 200 || !str_contains($page['body'], 'csrf')) {
        throw new RuntimeException("GET {$baseUrl}/ returned HTTP {$page['status']} without a CSRF token; is this the DTS web app?");
    }

    // Each kiosk loads the form before the barrier; transport errors become failure results instead of aborting the batch
    $openForm = function (int $i) use ($baseUrl, $verifyTls): array {
        try {
            $client = new HttpSession($baseUrl, $verifyTls);
            $client->get('/');
            return ['client' => $client, 'error' => null];
        } catch (\Throwable $e) {
            return ['client' => null, 'error' => $e->getMessage()];
        }
    };
    $work = function (int $i, array $prepared) use ($prefix, $purposeId, $department): array {
        $start = microtime(true);
        $base = ['status' => 0, 'location' => '', 'tracking_code' => null, 'document_id' => null, 'start' => $start, 'end' => $start, 'failure' => null];

        // A kiosk whose form never loaded cannot submit
        if ($prepared['client'] === null) {
            return array_merge($base, ['failure' => 'form load failed: ' . $prepared['error']]);
        }

        try {
            $response = $prepared['client']->post('/submit-document', [
                'guest_name' => "Live Concurrency Guest {$i}",
                'guest_email' => "live-guest{$i}@example.com",
                'guest_phone' => '09171234567',
                'district' => 'N/A',
                'department' => $department,
                'title' => $prefix . $i,
                'purpose_id' => (string) $purposeId,
            ]);
        } catch (\Throwable $e) {
            return array_merge($base, ['end' => microtime(true), 'failure' => $e->getMessage()]);
        }

        // The tracking code and document id travel in the redirect to the success page
        $location = $response['headers']['location'] ?? '';
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return array_merge($base, [
            'status' => $response['status'],
            'location' => $location,
            'tracking_code' => $query['tracking_code'] ?? null,
            'document_id' => isset($query['document_id']) && ctype_digit((string) $query['document_id']) ? (int) $query['document_id'] : null,
            'end' => microtime(true),
            'failure' => str_starts_with($location, '/success') ? null : "HTTP {$response['status']} to '{$location}'",
        ]);
    };

    // A worker that wrote no result (or the deadline) still leaves the others' results for verification and cleanup
    try {
        $results = Barrier::forkAll($workers, $work, $openForm);
    } catch (BarrierWorkerException $e) {
        $results = $e->results;
        $runError = $e;
        fwrite(STDERR, "RUN ERROR: " . $e->getMessage() . "\n");
    }

    // Timing: first POST leaving to last response arriving (skipped when no worker reported)
    if ($results !== []) {
        $metrics['wall_seconds'] = round(max(array_column($results, 'end')) - min(array_column($results, 'start')), 3);
        $metrics['max_request_seconds'] = round(max(array_map(fn($r) => $r['end'] - $r['start'], $results)), 3);
        $metrics['skew_seconds'] = round(Barrier::skew($results), 4);
    }

    // Verify every returned code against the database, as the automated test does
    $codes = array_filter(array_column($results, 'tracking_code'));
    foreach ($results as $i => $result) {
        // A success is a redirect to /success carrying a code
        if ($result['status'] === 302 && str_starts_with($result['location'], '/success') && $result['tracking_code']) {
            $metrics['successes']++;
        }
        $row = $result['tracking_code']
            ? $db->query('SELECT id, title FROM documents WHERE tracking_code = :c', [':c' => $result['tracking_code']])->fetch()
            : false;

        // The row behind the code must be this worker's own document, with the id the redirect reported
        if (!$row || $row['title'] !== $prefix . $i || (int) $row['id'] !== $result['document_id']) {
            $metrics['mismatches']++;
            continue;
        }

        // Exactly one 'Submitted' log per new document
        $logs = (int) $db->query("SELECT COUNT(*) c FROM document_logs WHERE document_id = :id AND action = 'Submitted'", [':id' => $row['id']])->fetch()['c'];
        if ($logs !== 1) {
            $metrics['submitted_log_faults']++;
        }
    }
    $metrics['distinct_codes'] = count(array_unique($codes));

    // Only this run's rows are counted; real traffic on the live server must not skew the figure
    $metrics['documents_added'] = count(LiveRunCleanup::findRunDocumentIds($pdo, $runId, $maxIdBefore));

    // Print the first few failures for diagnosis
    foreach (array_slice(array_filter($results, fn($r) => $r['failure'] !== null), 0, 5, true) as $i => $result) {
        echo "worker {$i}: {$result['failure']}\n";
    }
} catch (\Throwable $e) {
    $runError = $e;
    fwrite(STDERR, "RUN ERROR: " . get_class($e) . ': ' . $e->getMessage() . "\n");
} finally {
    $cleanup();
}

// Summary and verdict
$passed = $runError === null
    && $metrics['successes'] === $workers
    && $metrics['distinct_codes'] === $workers
    && $metrics['documents_added'] === $workers
    && $metrics['mismatches'] === 0
    && $metrics['submitted_log_faults'] === 0;
printf("\nSuccesses %d/%d, distinct codes %d, documents added %d, mismatches %d, log faults %d, wall %ss, slowest %ss, skew %ss\n",
    $metrics['successes'], $workers, $metrics['distinct_codes'], $metrics['documents_added'], $metrics['mismatches'],
    $metrics['submitted_log_faults'], $metrics['wall_seconds'], $metrics['max_request_seconds'], $metrics['skew_seconds']);
echo "Result row appended to " . ResultWriter::dir() . "/live-concurrency.csv (run_id {$runId}).\n";
echo $passed ? "PASS\n" : "FAIL\n";
exit($passed ? 0 : EXIT_FAILED);
