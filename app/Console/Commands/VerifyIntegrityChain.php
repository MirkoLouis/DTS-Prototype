<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class VerifyIntegrityChain extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dts:verify-integrity';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify the integrity of the document log hash chain.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting integrity verification of the document log hash chain...');

        $allLogs = \App\Models\DocumentLog::orderBy('id', 'asc')->get();
        $totalLogs = $allLogs->count();
        $invalidLogsCount = 0;
        $mismatchedIds = [];

        if ($totalLogs === 0) {
            $this->info('No document logs found to verify.');
            Cache::put('integrity-check-result', ['verified_percentage' => 100, 'last_checked' => now(), 'total_logs' => 0, 'invalid_logs' => 0, 'mismatched_ids' => []], now()->addHours(24));
            return 0;
        }

        // Group logs by document to verify each chain independently
        $logsByDocument = $allLogs->groupBy('document_id');

        $progressBar = $this->output->createProgressBar($totalLogs);
        $progressBar->start();

        foreach ($logsByDocument as $documentId => $logs) {
            $previousHash = 'genesis_hash'; // Reset for each new document chain

            foreach ($logs as $log) {
                // The hash is calculated from the log's own data PLUS the previous hash.
                // The timestamp format MUST be identical to the one used during creation.
                $timestampForHashing = Carbon::parse($log->created_at)->toIso8601String();
                $dataToHash = $log->document_id . $log->user_id . $log->action . $timestampForHashing . $previousHash;
                $recalculatedHash = hash('sha256', $dataToHash);

                if ($recalculatedHash !== $log->hash) {
                    $invalidLogsCount++;
                    $mismatchedIds[] = $log->id;
                }

                // The current hash becomes the next log's previous_hash for the calculation
                $previousHash = $log->hash;
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine();

        $verifiedPercentage = ($totalLogs > 0) ? (($totalLogs - $invalidLogsCount) / $totalLogs) * 100 : 100;

        Cache::put('integrity-check-result', [
            'verified_percentage' => round($verifiedPercentage, 2),
            'last_checked' => now(),
            'total_logs' => $totalLogs,
            'invalid_logs' => $invalidLogsCount,
            'mismatched_ids' => $mismatchedIds,
        ], now()->addHours(24));

        if ($invalidLogsCount > 0) {
            $this->error("Integrity check failed. Found {$invalidLogsCount} mismatched hashes.");
            $this->warn('Mismatched Log IDs: ' . implode(', ', $mismatchedIds));
            return 1;
        }

        $this->info('Successfully verified the integrity of all document logs.');
        return 0;
    }
}
