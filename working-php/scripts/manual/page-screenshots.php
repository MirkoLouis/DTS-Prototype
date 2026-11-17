<?php

/**
 * Manual browser checks: (1) responsive layout: screenshots every main page at 360, 1024 and 1280 px and reports horizontal
 * overflow (page-screenshots.mjs); (2) accessibility: keyboard pass of every modal dialog plus a markup audit of every
 * page at 1280 and 360 px (accessibility-check.mjs); (3) script policy and behaviour: every page by hard load and by in-app
 * navigation with the Content Security Policy violations (the policy is enforced), console errors and uncaught exceptions, the
 * moved features, and proof that the policy and the security headers are really in force (behavior-check.mjs). One launcher
 * runs all three.
 *
 * Usage (from working-php/):
 *   composer manual:page-screenshots            or            php scripts/manual/page-screenshots.php
 *
 * Builds a fresh TEST database with the suite's fixtures, starts the PHP built-in server in the testing environment
 * with rate limiting off, runs scripts/manual/page-screenshots.mjs (Node + playwright-core, see the README) and stops
 * the server. Refuses to run unless the configured database name ends in _test. Output: .test/screens/<width>/<name>.png.
 */

use App\Common\Config\Config;
use App\Common\Middleware\CacheMiddleware;
use App\Modules\Integrity\Jobs\ChainCheckpointJob;
use Tests\Support\Fixtures;
use Tests\Support\TestDatabase;
use Tests\Support\TestGuard;

// The testing environment must be set before anything reads the configuration
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';

define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/vendor/autoload.php';
date_default_timezone_set(\App\Modules\Integrity\HashChainService::APP_TIMEZONE);

// Print a refusal to STDERR and stop
function refuse(string $message): never
{
    fwrite(STDERR, "Refusing to run: {$message}\n");
    exit(2);
}

// Gate 1: .env.testing must exist, otherwise the configuration would be empty or point elsewhere
if (!file_exists(BASE_PATH . '/.env.testing')) {
    refuse('.env.testing is missing (copy .env.testing.example).');
}

// Gate 2: the configured database must be the disposable test database
$config = require BASE_PATH . '/src/Common/Config/app.config.php';
try {
    TestGuard::assertSafe((string) $config['app']['env'], (string) $config['database']['dbname']);
} catch (RuntimeException $e) {
    refuse($e->getMessage());
}

// Create the test database when it does not exist yet (guard already passed for this exact name)
$db = $config['database'];
$serverConnection = new PDO(
    "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4",
    $db['user'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$serverConnection->exec('CREATE DATABASE IF NOT EXISTS `' . $db['dbname'] . '` CHARACTER SET utf8mb4');

// Cache, session and checkpoint folders live under .test/ (only the parts this tool owns are emptied)
foreach (glob(CacheMiddleware::cacheDir() . '/*') ?: [] as $cachedPage) {
    @unlink($cachedPage);
}
foreach ([CacheMiddleware::cacheDir(), ChainCheckpointJob::directory(), Config::testPath('screens')] as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
}

// Fresh schema, baseline users, then documents in several states so the lists are not empty
TestDatabase::reset();
$fixture = TestDatabase::seedBaseline();

// More documents than one list page holds (15), created first so they are the oldest and sit on the second list page: the
// lists have pagination links and the documents below stay on the first page
for ($filler = 1; $filler <= 12; $filler++) {
    Fixtures::submit(['title' => "Pagination filler document {$filler}"]);
}
$pending = Fixtures::submit(['title' => 'Request for certified true copy of a long document title to test wrapping']);
$inTransit = Fixtures::submit(['title' => 'Application documents in transit']);
Fixtures::advanceTo($inTransit['id'], 'in_transit');
$processing = Fixtures::submit(['title' => 'Document being processed']);
Fixtures::advanceTo($processing['id'], 'processing');
$readyForRelease = Fixtures::submit(['title' => 'Document ready for release']);
Fixtures::advanceTo($readyForRelease['id'], 'ready_for_release');
$completed = Fixtures::submit(['title' => 'Completed document']);
Fixtures::advanceTo($completed['id'], 'completed');
$declined = Fixtures::submit(['title' => 'Declined document']);
Fixtures::advanceTo($declined['id'], 'declined');


// A spare fixture user without a signing key, to show the mandatory Security PIN prompt (same columns as UserCredentialService)
$keylessUser = 'personnel.unit';
\App\Common\Database\Database::getInstance()->query(
    'UPDATE users SET public_key = NULL, private_key = NULL, security_key_set_at = NULL WHERE email = :email',
    [':email' => $keylessUser]
);

// A stored integrity-check result that names one log entry of the completed document, so System Overview lists an issue with a
// real "Verify Hash" button (the log itself is untouched; the page's debug endpoint recomputes its hash). Written under .test/cache.
$resultPath = \App\Modules\Integrity\Jobs\IntegrityCheckJob::resultPath();
if (!is_dir(dirname($resultPath))) {
    mkdir(dirname($resultPath), 0755, true);
}
$flaggedLogId = (int) \App\Common\Database\Database::getInstance()->query(
    'SELECT id FROM document_logs WHERE document_id = :id ORDER BY id LIMIT 1',
    [':id' => $completed['id']]
)->fetchColumn();
file_put_contents($resultPath, json_encode([
    'verified_percentage' => 50,
    'last_checked' => date('Y-m-d H:i:s'),
    'mismatched_ids' => [$flaggedLogId],
    'mismatched_document_tracking_codes' => [],
]));

// One failed job, so System Overview offers "View Details", "Clear ALL failed jobs" and per-job "Resolve" (confirm-action forms)
\App\Common\Database\Database::getInstance()->query(
    'INSERT INTO failed_jobs (uuid, connection, queue, payload, exception) VALUES (:uuid, :connection, :queue, :payload, :exception)',
    [':uuid' => 'fixture-failed-job-1', ':connection' => 'database', ':queue' => 'default', ':payload' => json_encode(['displayName' => 'ExampleJob']), ':exception' => 'Example failure for the accessibility check']
);

// One placeholder backup archive (under .test/) so the Backup Manager lists a row with its Restore button
$backupDirectory = \App\Modules\System\BackupService::directory();
if (!is_dir($backupDirectory)) {
    mkdir($backupDirectory, 0755, true);
}
file_put_contents($backupDirectory . '/example-backup.zip', '');

// Find a free port by binding port 0 and releasing it
$probe = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
$port = (int) explode(':', stream_socket_get_name($probe, false))[1];
fclose($probe);

// Start the built-in server in the testing environment with rate limiting off
$serverEnvironment = array_merge(getenv(), ['APP_ENV' => 'testing', 'RATE_LIMIT_ENABLED' => 'false', 'PHP_CLI_SERVER_WORKERS' => '8']);
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', 'public', 'public/index.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    BASE_PATH,
    $serverEnvironment
);
if (!is_resource($server)) {
    refuse('could not start the PHP built-in server.');
}

// Stop the server however the script ends
$stopServer = function () use (&$server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
        $server = null;
    }
};
register_shutdown_function($stopServer);

// Wait until the port accepts connections
$listening = false;
for ($attempt = 0; $attempt < 100 && !$listening; $attempt++) {
    $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.1);
    if ($socket) {
        fclose($socket);
        $listening = true;
        continue;
    }
    usleep(50000);
}
if (!$listening) {
    $stopServer();
    refuse('the PHP built-in server did not start in time.');
}

// Prove through the server's own probe that it runs the testing environment on a _test database
$probeBody = @file_get_contents("http://127.0.0.1:{$port}/__test/env");
$probeData = is_string($probeBody) ? json_decode($probeBody, true) : null;
if (!is_array($probeData) || !is_string($probeData['env'] ?? null) || !is_string($probeData['db'] ?? null)) {
    $stopServer();
    refuse('the server has no valid /__test/env probe.');
}
try {
    TestGuard::assertSafe($probeData['env'], $probeData['db']);
} catch (RuntimeException $e) {
    $stopServer();
    refuse($e->getMessage());
}

// Facts the Node script needs to build its page list
$context = [
    'baseUrl' => "http://127.0.0.1:{$port}",
    'password' => TestDatabase::PASSWORD,
    'pin' => TestDatabase::PIN,
    'trackingCode' => $pending['tracking_code'],
    'documentCode' => $inTransit['tracking_code'],
    'documentId' => $pending['id'],
    'keylessUser' => $keylessUser,
    'editUserId' => $fixture['users']['cash.unit'],
    'screensDirectory' => Config::testPath('screens'),
];
$contextFile = Config::testPath('screens/context.json');
file_put_contents($contextFile, json_encode($context, JSON_PRETTY_PRINT));

// Run the Node scripts one after the other in the foreground (layout check first, then the accessibility check,
// which also creates report and integrity jobs, then the policy and behaviour check) and pass on the worst exit code
$exitCode = 0;
foreach (['page-screenshots.mjs', 'accessibility-check.mjs', 'behavior-check.mjs'] as $script) {
    // The output (stderr merged into stdout) is relayed through a pipe: children that inherit a redirected STDOUT/STDERR
    // file reset its offset, so the second script would overwrite the lines of the first
    $node = proc_open(['node', __DIR__ . '/' . $script, $contextFile], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $nodePipes, BASE_PATH);
    if (!is_resource($node)) {
        $exitCode = 1;
        continue;
    }
    while (($line = fgets($nodePipes[1])) !== false) {
        fwrite(STDOUT, $line);
    }
    fclose($nodePipes[1]);
    $exitCode = max($exitCode, proc_close($node));
}
$stopServer();
exit($exitCode);
