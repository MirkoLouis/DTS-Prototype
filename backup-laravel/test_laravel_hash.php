<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$log = App\Models\DocumentLog::find(1);
$timestampForHashing = \Carbon\Carbon::parse($log->created_at)->startOfSecond()->toIso8601String();
$dataToHash = $log->document_id . '|' . 
             $log->user_id . '|' . 
             $log->action . '|' . 
             $timestampForHashing . '|' . 
             $log->previous_hash . '|' . 
             $log->document_state_hash . '|' . 
             $log->signature;

echo "Laravel Hash: " . hash('sha256', $dataToHash) . "\n";
echo "DB Hash:      " . $log->hash . "\n";
echo "Data: " . $dataToHash . "\n";
