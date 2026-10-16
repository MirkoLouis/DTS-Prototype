<?php

$envPath = BASE_PATH . '/.env';
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
        'host' => $env['DB_HOST'],
        'port' => $env['DB_PORT'],
        'dbname' => $env['DB_DATABASE'],
        'user' => $env['DB_USERNAME'],
        'password' => $env['DB_PASSWORD'],
        'charset' => 'utf8mb4'
    ],
    // Application runtime and cryptographic settings
    'app' => [
        'debug' => filter_var($env['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
        'pin_cache_duration' => (int) ($env['PIN_CACHE_DURATION'] ?? 900),
        'system_signing_secret' => $env['SYSTEM_SIGNING_SECRET'] ?? '',
        'system_signing_public' => $env['SYSTEM_SIGNING_PUBLIC'] ?? ''
    ]
];
