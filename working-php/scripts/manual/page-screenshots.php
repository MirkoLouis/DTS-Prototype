<?php

/**
 * Manual responsive-layout check: screenshots every main page at 360 px and 1280 px and reports horizontal overflow.
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
    'trackingCode' => $pending['tracking_code'],
    'documentCode' => $inTransit['tracking_code'],
    'documentId' => $pending['id'],
    'keylessUser' => $keylessUser,
    'editUserId' => $fixture['users']['cash.unit'],
    'screensDirectory' => Config::testPath('screens'),
];
$contextFile = Config::testPath('screens/context.json');
file_put_contents($contextFile, json_encode($context, JSON_PRETTY_PRINT));

// Run the Node script in the foreground and pass its exit code on
$command = [
    'node',
    __DIR__ . '/page-screenshots.mjs',
    $contextFile,
];
$node = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $nodePipes, BASE_PATH);
$exitCode = is_resource($node) ? proc_close($node) : 1;
$stopServer();
exit($exitCode);
