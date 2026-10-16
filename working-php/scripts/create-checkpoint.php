<?php

/**
 * Write a signed chain-head checkpoint now (console.php also does this every 24 hours).
 * Usage: php scripts/create-checkpoint.php
 */

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
date_default_timezone_set('Asia/Manila');

try {
    $path = (new \App\Jobs\ChainCheckpointJob())->create();
    echo "Checkpoint written: {$path}\n";
    echo "Copy it off this machine (USB, email, print) so it is independent of the database server.\n";
} catch (\Throwable $e) {
    echo "Checkpoint failed: " . $e->getMessage() . "\n";
    exit(1);
}
