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
    protected $description = 'Verify the integrity of the document log hash chain and the live state of all documents.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Step 1/2: Verifying historical log hash chain integrity...');

        $totalLogs = \App\Models\DocumentLog::count();
        $invalidLogsCount = 0;
        $mismatchedIds = [];

        if ($totalLogs > 0) {
            $progressBar = $this->output->createProgressBar($totalLogs);
            $progressBar->start();

            // We track the last hash for each document across chunks
            $lastHashesByDocument = [];

            // Process logs in chunks of 1000 to keep memory usage low and constant
            \App\Models\DocumentLog::orderBy('document_id', 'asc')
                ->orderBy('id', 'asc')
                ->chunkById(1000, function ($logs) use (&$invalidLogsCount, &$mismatchedIds, &$lastHashesByDocument, $progressBar) {
                    foreach ($logs as $log) {
                        // Determine the expected previous hash for this log
                        $expectedPreviousHash = $lastHashesByDocument[$log->document_id] ?? 'genesis_hash';

                        // The timestamp format MUST be identical to the one used during creation.
                        $timestampForHashing = Carbon::parse($log->created_at)->toIso8601String();
                        
                        // We now verify against the document_state_hash and digital signature
                        $dataToHash = $log->document_id . $log->user_id . $log->action . $timestampForHashing . $expectedPreviousHash . $log->document_state_hash . $log->signature;
                        $recalculatedHash = hash('sha256', $dataToHash);

                        if ($recalculatedHash !== $log->hash) {
                            $invalidLogsCount++;
                            $mismatchedIds[] = $log->id;
                        }

                        // Store the current hash as the previous hash for the next log in this document's chain
                        $lastHashesByDocument[$log->document_id] = $log->hash;
                        $progressBar->advance();
                    }
                });

            $progressBar->finish();
            $this->newLine();
        } else {
            $this->info('No document logs found to verify.');
        }

        $this->info('Step 2/2: Verifying live document state integrity (Active State Comparison)...');
        
        $totalDocuments = \App\Models\Document::count();
        $liveStateErrorsCount = 0;
        $mismatchedDocumentTrackingCodes = [];

        if ($totalDocuments > 0) {
            $docProgressBar = $this->output->createProgressBar($totalDocuments);
            $docProgressBar->start();

            // Iterate through documents to check if their current DB state matches the latest recorded log state
            \App\Models\Document::chunk(500, function ($documents) use (&$liveStateErrorsCount, &$mismatchedDocumentTrackingCodes, $docProgressBar) {
                foreach ($documents as $document) {
                    // Get the latest log entry for this specific document
                    $latestLog = $document->logs()->orderBy('id', 'desc')->first();
                    
                    if ($latestLog) {
                        // Calculate what the state hash SHOULD be based on current DB values
                        $currentStateHash = \App\Models\DocumentLog::calculateStateHash($document);
                        
                        // If it doesn't match the hash in the latest log, someone modified the document
                        // without a corresponding log entry being generated (or tampered with the log).
                        if ($currentStateHash !== $latestLog->document_state_hash) {
                            $liveStateErrorsCount++;
                            $mismatchedDocumentTrackingCodes[] = $document->tracking_code;

                            // Dispatch the IntegrityCheckFailed event to auto-freeze the document
                            \App\Events\IntegrityCheckFailed::dispatch($document, 'Verification Scan');
                        }
                    }
                    $docProgressBar->advance();
                }
            });

            $docProgressBar->finish();
            $this->newLine();
        }

        $verifiedPercentage = ($totalLogs > 0) ? (($totalLogs - $invalidLogsCount) / $totalLogs) * 100 : 100;

        // Store results in cache for the admin dashboard
        Cache::put('integrity-check-result', [
            'verified_percentage' => round($verifiedPercentage, 2),
            'last_checked' => now(),
            'total_logs' => $totalLogs,
            'invalid_logs' => $invalidLogsCount,
            'mismatched_ids' => $mismatchedIds,
            'live_state_errors_count' => $liveStateErrorsCount,
            'mismatched_document_tracking_codes' => $mismatchedDocumentTrackingCodes,
        ], now()->addHours(24));

        if ($invalidLogsCount > 0 || $liveStateErrorsCount > 0) {
            if ($invalidLogsCount > 0) {
                $this->error("Historical chain check failed: Found {$invalidLogsCount} mismatched hashes.");
            }
            if ($liveStateErrorsCount > 0) {
                $this->error("Live state check failed: Found {$liveStateErrorsCount} documents with tampered states.");
            }
            return 1;
        }

        $this->info('Successfully verified all historical logs and live document states.');
        return 0;
    }
}
