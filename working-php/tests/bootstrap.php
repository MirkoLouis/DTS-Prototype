<?php

use Tests\Support\TestGuard;

// Force the testing environment before anything reads the configuration
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
date_default_timezone_set(\App\Integrity\HashChainMicroShardingBuilder::APP_TIMEZONE);

// Without .env.testing the config would be empty; stop before any connection is attempted
if (!file_exists(BASE_PATH . '/.env.testing')) {
    fwrite(STDERR, "Refusing to run: tests need APP_ENV=testing and a database whose name ends in _test.\n");
    fwrite(STDERR, "Missing .env.testing: copy .env.testing.example to .env.testing first.\n");
    exit(1);
}

// Refuse to run unless the configuration targets a disposable test database
$config = require BASE_PATH . '/src/Config/config.php';
try {
    TestGuard::assertSafe((string) $config['app']['env'], (string) $config['database']['dbname']);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

// One results folder per run, exported so forked children and subprocesses inherit it
\App\Research\ResultWriter::dir();

// System-initiated log entries need the server key in .env.testing
if ($config['app']['system_signing_secret'] === '') {
    fwrite(STDERR, "System signing key missing in .env.testing. Run: APP_ENV=testing php scripts/generate-keys.php --system\n");
    exit(1);
}

// Fresh database and -test artifacts for every run; removed again when the suite ends
Tests\Support\TestLifecycle::begin($config['database']);

// Only the parent PHPUnit process cleans up; forked children (which SIGKILL themselves) must not
$lifecycleOwnerPid = getmypid();
register_shutdown_function(function () use ($lifecycleOwnerPid, $config) {
    if (getmypid() === $lifecycleOwnerPid) {
        Tests\Support\TestLifecycle::end($config['database']);
    }
});
