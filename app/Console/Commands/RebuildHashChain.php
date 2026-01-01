<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DocumentLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class RebuildHashChain extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dts:rebuild-chain {logId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rebuilds the hash chain for a document starting from a specific log entry.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startLogId = $this->argument('logId');
        $startLog = DocumentLog::find($startLogId);

        if (!$startLog) {
            $this->error("DocumentLog with ID {$startLogId} not found.");
            return 1;
        }

        $this->info("Starting hash chain rebuild for Document ID: {$startLog->document_id} from Log ID: {$startLogId}.");

        // Get all subsequent logs for the same document that need to be re-chained
        $logsToRebuild = DocumentLog::where('document_id', $startLog->document_id)
                                    ->where('id', '>=', $startLogId)
                                    ->orderBy('id', 'asc')
                                    ->get();

        // Get the log just before our starting point to get the last valid hash
        $lastValidLog = DocumentLog::where('document_id', $startLog->document_id)
                                    ->where('id', '<', $startLogId)
                                    ->orderBy('id', 'desc')
                                    ->first();

        $previousHash = $lastValidLog ? $lastValidLog->hash : 'genesis_hash';

        foreach ($logsToRebuild as $log) {
            $this->line("Re-hashing log ID: {$log->id}...");

            $log->previous_hash = $previousHash;
            
            $timestampForHashing = Carbon::parse($log->created_at)->toIso8601String();
            $dataToHash = $log->document_id . $log->user_id . $log->action . $timestampForHashing . $previousHash;
            $newHash = hash('sha256', $dataToHash);

            $log->hash = $newHash;
            $log->saveQuietly(); // Use saveQuietly to prevent the 'creating'/'updating' event from firing again

            $previousHash = $newHash;
        }

        // Create a final log entry to record this administrative action
        DocumentLog::create([
            'document_id' => $startLog->document_id,
            'user_id' => Auth::id() ?? User::where('role', 'admin')->first()->id, // Fallback for CLI
            'action' => 'ADMIN: Hash chain rebuilt for Log ID: ' . $startLogId,
            'remarks' => 'An administrator manually triggered a hash chain rebuild to resolve an integrity mismatch.',
        ]);

        $this->info("Successfully rebuilt hash chain for Document ID: {$startLog->document_id}.");
        return 0;
    }
}
