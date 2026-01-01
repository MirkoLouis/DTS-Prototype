<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DocumentLog;

class CorruptDocumentLog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dts:corrupt-log {logId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Corrupts a specific DocumentLog entry to test integrity checks.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $logId = $this->argument('logId');
        $log = DocumentLog::find($logId);

        if (!$log) {
            $this->error("DocumentLog with ID {$logId} not found.");
            return 1;
        }

        $oldAction = $log->action;
        $log->action = 'CORRUPTED: ' . $oldAction;
        $log->save();

        $this->info("DocumentLog with ID {$logId} corrupted successfully. Original action: '{$oldAction}', New action: '{$log->action}'.");
        $this->warn('This log will now fail integrity checks.');

        return 0;
    }
}

