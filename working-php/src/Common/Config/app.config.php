<?php

// The test suite sets APP_ENV=testing so it reads .env.testing and never touches the operational .env
$envPath = BASE_PATH . (getenv('APP_ENV') === 'testing' ? '/.env.testing' : '/.env');
$env = [];

if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $env[trim($name)] = trim($value, " \t\n\r\0\x0B\"");
        }
    }
}

return [
    'database' => [
        'host' => $env['DB_HOST'] ?? '127.0.0.1',
        'port' => $env['DB_PORT'] ?? '3306',
        'dbname' => $env['DB_DATABASE'] ?? '',
        'user' => $env['DB_USERNAME'] ?? '',
        'password' => $env['DB_PASSWORD'] ?? '',
        'charset' => 'utf8mb4'
    ],
    // Application runtime and cryptographic settings
    'app' => [
        'env' => $env['APP_ENV'] ?? 'production',
        'debug' => filter_var($env['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
        'pin_cache_duration' => (int) ($env['PIN_CACHE_DURATION'] ?? 900),
        'system_signing_secret' => $env['SYSTEM_SIGNING_SECRET'] ?? '',
        'system_signing_public' => $env['SYSTEM_SIGNING_PUBLIC'] ?? '',
        // Seconds between a running job's health pings, and how long without one before it is recorded as abandoned
        'job_heartbeat_seconds' => (int) ($env['JOB_HEARTBEAT_SECONDS'] ?? 10),
        'job_abandoned_seconds' => (int) ($env['JOB_ABANDONED_SECONDS'] ?? 60),
        // Days a backup is kept before it is deleted (the newest backup always stays); 0 keeps every backup
        'backup_keep_days' => (int) ($env['BACKUP_KEEP_DAYS'] ?? 14)
    ]
];
