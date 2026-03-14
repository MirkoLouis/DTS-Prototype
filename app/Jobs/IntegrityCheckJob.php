<?php

namespace App\Jobs;

use App\Models\IntegrityCheck;
use App\Models\PublicKeyHistory;
use App\Models\DocumentLog;
use App\Models\Document;
use App\Events\IntegrityCheckFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Throwable;

class IntegrityCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $integrityCheck;
    public $timeout = 1200;

    public function __construct(IntegrityCheck $integrityCheck)
    {
        $this->integrityCheck = $integrityCheck;
    }

    public function handle(): void
    {
        $this->integrityCheck->update(['status' => 'processing', 'progress' => 5]);

        try {
            // Step 1: Verifying historical log hash chain integrity...
            $totalLogs = DocumentLog::count();
            $invalidLogsCount = 0;
            $invalidSignaturesCount = 0;
            $mismatchedIds = [];
            $lastHashesByDocument = [];
            $processedLogs = 0;

            if ($totalLogs > 0) {
                DocumentLog::with('user')->orderBy('document_id', 'asc')
                    ->orderBy('id', 'asc')
                    ->chunkById(500, function ($logs) use (&$invalidLogsCount, &$invalidSignaturesCount, &$mismatchedIds, &$lastHashesByDocument, &$processedLogs, $totalLogs) {
                        
                        $this->integrityCheck->refresh();
                        if ($this->integrityCheck->status === 'cancelled') throw new \Exception("Job cancelled by user.");

                        foreach ($logs as $log) {
                            // 1. Verify Hash Chain Consistency
                            $expectedPreviousHash = $lastHashesByDocument[$log->document_id] ?? 'genesis_hash';
                            $timestampForHashing = Carbon::parse($log->created_at)->startOfSecond()->toIso8601String();
                            
                            $dataToHash = $log->document_id . '|' . 
                                         $log->user_id . '|' . 
                                         $log->action . '|' . 
                                         $timestampForHashing . '|' . 
                                         $expectedPreviousHash . '|' . 
                                         $log->document_state_hash . '|' . 
                                         $log->signature;

                            $recalculatedHash = hash('sha256', $dataToHash);

                            if ($recalculatedHash !== $log->hash) {
                                $invalidLogsCount++;
                                $mismatchedIds[] = $log->id;
                            }

                            // 2. Verify Cryptographic Signature
                            if ($log->signature && strlen($log->signature) > 10 && $log->user) {
                                $signedData = $log->action . '|' . $log->document_state_hash;
                                $isMockSignature = str_starts_with(base64_decode($log->signature), 'MOCK_SIG:');

                                if ($isMockSignature) {
                                    $decodedMock = base64_decode($log->signature);
                                    $expectedMock = "MOCK_SIG:{$log->action}|{$log->document_state_hash}";
                                    if ($decodedMock !== $expectedMock) {
                                        $invalidSignaturesCount++;
                                        if (!in_array($log->id, $mismatchedIds)) $mismatchedIds[] = $log->id;
                                    }
                                } else {
                                    $publicKey = $this->getPublicKeyAtTime($log->user, $log->created_at);
                                    if ($publicKey) {
                                        if (!DocumentLog::verifySignature($log->signature, $signedData, $publicKey)) {
                                            $invalidSignaturesCount++;
                                            if (!in_array($log->id, $mismatchedIds)) $mismatchedIds[] = $log->id;
                                        }
                                    } else {
                                        $invalidSignaturesCount++;
                                        if (!in_array($log->id, $mismatchedIds)) $mismatchedIds[] = $log->id;
                                    }
                                }
                            }

                            $lastHashesByDocument[$log->document_id] = $log->hash;
                            $processedLogs++;
                        }

                        $percent = 5 + floor(($processedLogs / $totalLogs) * 45);
                        $this->integrityCheck->update(['progress' => $percent]);
                    });
            }

            // Step 2: Verifying live document state integrity
            $totalDocuments = Document::count();
            $liveStateErrorsCount = 0;
            $mismatchedDocumentTrackingCodes = [];
            $processedDocs = 0;

            if ($totalDocuments > 0) {
                Document::chunk(500, function ($documents) use (&$liveStateErrorsCount, &$mismatchedDocumentTrackingCodes, &$processedDocs, $totalDocuments) {
                    
                    $this->integrityCheck->refresh();
                    if ($this->integrityCheck->status === 'cancelled') throw new \Exception("Job cancelled by user.");

                    foreach ($documents as $document) {
                        $latestLog = $document->logs()->orderBy('id', 'desc')->first();
                        if ($latestLog) {
                            $currentStateHash = DocumentLog::calculateStateHash($document);
                            if ($currentStateHash !== $latestLog->document_state_hash) {
                                $liveStateErrorsCount++;
                                $mismatchedDocumentTrackingCodes[] = $document->tracking_code;
                                IntegrityCheckFailed::dispatch($document, 'Verification Scan');
                            }
                        }
                        $processedDocs++;
                    }
                    $percent = 50 + floor(($processedDocs / $totalDocuments) * 45);
                    $this->integrityCheck->update(['progress' => $percent]);
                });
            }

            $verifiedPercentage = ($totalLogs > 0) ? (($totalLogs - (count($mismatchedIds))) / $totalLogs) * 100 : 100;

            $results = [
                'verified_percentage' => round($verifiedPercentage, 2),
                'last_checked' => now(),
                'total_logs' => $totalLogs,
                'invalid_logs' => $invalidLogsCount,
                'invalid_signatures' => $invalidSignaturesCount,
                'mismatched_ids' => $mismatchedIds,
                'live_state_errors_count' => $liveStateErrorsCount,
                'mismatched_document_tracking_codes' => $mismatchedDocumentTrackingCodes,
            ];

            // Store results in cache for the dashboard compatibility
            Cache::put('integrity-check-result', $results, now()->addHours(24));

            $this->integrityCheck->update([
                'status' => 'completed',
                'progress' => 100,
                'results' => $results,
            ]);

        } catch (Throwable $e) {
            $this->integrityCheck->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    protected function getPublicKeyAtTime($user, $timestamp)
    {
        $timestamp = Carbon::parse($timestamp);
        $historicalKey = PublicKeyHistory::where('user_id', $user->id)
            ->where('activated_at', '<=', $timestamp)
            ->where('deactivated_at', '>=', $timestamp)
            ->first();

        if ($historicalKey) return $historicalKey->public_key;

        if ($user->public_key) {
            $hasHistory = PublicKeyHistory::where('user_id', $user->id)->exists();
            if (!$hasHistory) return $user->public_key;
            if ($user->security_key_set_at && $user->security_key_set_at <= $timestamp) return $user->public_key;
        }

        return null;
    }
}
