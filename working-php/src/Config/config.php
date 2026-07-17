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
        'host' => $env['DB_HOST'] ?? '127.0.0.1',
        'port' => $env['DB_PORT'] ?? '3306',
        'dbname' => $env['DB_DATABASE'] ?? 'deped_dts',
        'user' => $env['DB_USERNAME'] ?? 'root',
        'password' => $env['DB_PASSWORD'] ?? '',
        'charset' => 'utf8mb4'
    ]
];
