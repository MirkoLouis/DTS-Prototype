<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Models\PublicKeyHistory;

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
        $invalidSignaturesCount = 0;
        $mismatchedIds = [];

        if ($totalLogs > 0) {
            $progressBar = $this->output->createProgressBar($totalLogs);
            $progressBar->start();

            // We track the last hash for each document across chunks
            $lastHashesByDocument = [];

            // Process logs in chunks of 1000 to keep memory usage low and constant
            \App\Models\DocumentLog::with('user')->orderBy('document_id', 'asc')
                ->orderBy('id', 'asc')
                ->chunkById(1000, function ($logs) use (&$invalidLogsCount, &$invalidSignaturesCount, &$mismatchedIds, &$lastHashesByDocument, $progressBar) {
                    foreach ($logs as $log) {
                        // 1. Verify Hash Chain Consistency
                        $expectedPreviousHash = $lastHashesByDocument[$log->document_id] ?? 'genesis_hash';
                        $timestampForHashing = Carbon::parse($log->created_at)->startOfSecond()->toIso8601String();
                        
                        $dataToHash = $log->document_id . $log->user_id . $log->action . $timestampForHashing . $expectedPreviousHash . $log->document_state_hash . $log->signature;
                        $recalculatedHash = hash('sha256', $dataToHash);

                        if ($recalculatedHash !== $log->hash) {
                            $invalidLogsCount++;
                            $mismatchedIds[] = $log->id;
                        }

                        // 2. Verify Cryptographic Signature (Atomic Bonding)
                        // Only verify logs that have real signatures (length > 64 chars implies Ed25519 vs fallback strings)
                        if ($log->signature && strlen($log->signature) > 64 && $log->user) {
                            $signedData = $log->action . '|' . $log->document_state_hash;
                            
                            // Find the public key that was active when this log was created
                            $publicKey = $this->getPublicKeyAtTime($log->user, $log->created_at);

                            if ($publicKey) {
                                $isValidSignature = \App\Models\DocumentLog::verifySignature(
                                    $log->signature,
                                    $signedData,
                                    $publicKey
                                );

                                if (!$isValidSignature) {
                                    $invalidSignaturesCount++;
                                    if (!in_array($log->id, $mismatchedIds)) {
                                        $mismatchedIds[] = $log->id;
                                    }
                                }
                            } else {
                                // If no public key was active at that time, but a signature exists, it's a mismatch
                                $invalidSignaturesCount++;
                                if (!in_array($log->id, $mismatchedIds)) {
                                    $mismatchedIds[] = $log->id;
                                }
                            }
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

        $verifiedPercentage = ($totalLogs > 0) ? (($totalLogs - (count($mismatchedIds))) / $totalLogs) * 100 : 100;

        // Store results in cache for the admin dashboard
        Cache::put('integrity-check-result', [
            'verified_percentage' => round($verifiedPercentage, 2),
            'last_checked' => now(),
            'total_logs' => $totalLogs,
            'invalid_logs' => $invalidLogsCount,
            'invalid_signatures' => $invalidSignaturesCount,
            'mismatched_ids' => $mismatchedIds,
            'live_state_errors_count' => $liveStateErrorsCount,
            'mismatched_document_tracking_codes' => $mismatchedDocumentTrackingCodes,
        ], now()->addHours(24));

        if ($invalidLogsCount > 0 || $invalidSignaturesCount > 0 || $liveStateErrorsCount > 0) {
            if ($invalidLogsCount > 0) {
                $this->error("Historical chain check failed: Found {$invalidLogsCount} mismatched hashes.");
            }
            if ($invalidSignaturesCount > 0) {
                $this->error("Cryptographic signature check failed: Found {$invalidSignaturesCount} invalid signatures.");
            }
            if ($liveStateErrorsCount > 0) {
                $this->error("Live state check failed: Found {$liveStateErrorsCount} documents with tampered states.");
            }
            return 1;
        }

        $this->info('Successfully verified all historical logs and live document states.');
        return 0;
    }

    /**
     * Resolve the public key that was active for a user at a specific timestamp.
     */
    protected function getPublicKeyAtTime($user, $timestamp)
    {
        // Ensure timestamp is a Carbon instance for comparison
        $timestamp = Carbon::parse($timestamp);

        // 1. Check history first
        $historicalKey = PublicKeyHistory::where('user_id', $user->id)
            ->where('activated_at', '<=', $timestamp)
            ->where('deactivated_at', '>=', $timestamp)
            ->first();

        if ($historicalKey) {
            return $historicalKey->public_key;
        }

        // 2. Check current key
        if ($user->public_key) {
            // If the user has a key but no history entries yet, this MUST be their first key.
            // We trust it for all their logs.
            $hasHistory = PublicKeyHistory::where('user_id', $user->id)->exists();
            if (!$hasHistory) {
                return $user->public_key;
            }

            // If they have history, the current key is only valid for logs created 
            // after (or at) the most recent 'security_key_set_at' timestamp.
            if ($user->security_key_set_at && $user->security_key_set_at <= $timestamp) {
                return $user->public_key;
            }
        }

        return null;
    }
}
