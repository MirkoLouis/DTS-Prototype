<?php

namespace App\Jobs;

use App\Core\Database;
use App\Core\IntegrityManager;
use App\Events\IntegrityCheckFailed;
use App\Core\EventDispatcher;

class IntegrityCheckJob
{
    // Maximum number of mismatch IDs to store in-memory and persist to the results JSON.
    // The audit still COUNTS all mismatches accurately beyond this cap; only the stored list is bounded.
    private const MAX_TRACKED_MISMATCHES = 1000;

    protected $integrityCheckId;

    public function __construct(string $integrityCheckId)
    {
        $this->integrityCheckId = $integrityCheckId;
    }

    // Executes a full cryptographic audit of the system. Verifies the SHA-256 hash chain, 
    // Ed25519 signatures against historical public keys, and live document states.
    public function handle(): void
    {
        date_default_timezone_set('Asia/Manila');
        $db = Database::getInstance();
        
        $db->query("UPDATE integrity_checks SET status = 'processing', progress = 5, updated_at = NOW() WHERE id = :id", [
            'id' => $this->integrityCheckId
        ]);

        try {
            $totalLogs = $db->query("SELECT COUNT(*) as c FROM document_logs")->fetch()['c'] ?? 0;
            $totalDocuments = $db->query("SELECT COUNT(*) as c FROM documents")->fetch()['c'] ?? 0;
            
            $invalidLogsCount = 0;
            $invalidSignaturesCount = 0;
            // Associative array used as a hashset for O(1) duplicate detection (replaces O(n) in_array calls).
            $mismatchedIdsSet = [];
            // Capped list stored for admin display — prevents a massive JSON blob from being written to the DB.
            $mismatchedIdsList = [];
            // True total mismatch count, accurate even when mismatches exceed MAX_TRACKED_MISMATCHES.
            $mismatchedIdsCount = 0;
            $liveStateErrorsCount = 0;
            $mismatchedDocumentTrackingCodes = [];
            
            $processedDocs = 0;
            $processedLogs = 0;

            if ($totalDocuments > 0) {
                $lastDocId = 0;
                $docChunkSize = 2000;
                
                while (true) {
                    $docsStmt = $db->query("SELECT * FROM documents WHERE id > :last_id ORDER BY id ASC LIMIT " . $docChunkSize, ['last_id' => $lastDocId]);
                    $docsChunk = $docsStmt->fetchAll();
                    
                    if (empty($docsChunk)) {
                        break;
                    }
                    
                    $docIds = array_column($docsChunk, 'id');
                    $placeholders = implode(',', array_fill(0, count($docIds), '?'));
                    
                    // Fetch all logs for this chunk of documents
                    $logsStmt = $db->query("SELECT dl.* FROM document_logs dl WHERE dl.document_id IN ($placeholders) ORDER BY dl.document_id ASC, dl.id ASC", $docIds);
                    $logsChunk = $logsStmt->fetchAll();

                    // Group logs by document ID
                    $logsByDoc = [];
                    foreach ($logsChunk as $log) {
                        $logsByDoc[$log['document_id']][] = $log;
                    }
                    
                    foreach ($docsChunk as $documentData) {
                        $docId = $documentData['id'];
                        $lastDocId = $docId;
                        
                        if ($processedDocs % 500 === 0) {
                            $statusCheck = $db->query("SELECT status FROM integrity_checks WHERE id = :id", ['id' => $this->integrityCheckId])->fetch();
                            if ($statusCheck && $statusCheck['status'] === 'cancelled') {
                                throw new \Exception("Job cancelled by user.");
                            }
                        }

                        $docLogs = $logsByDoc[$docId] ?? [];
                        $expectedPreviousHash = IntegrityManager::GENESIS_HASH;
                        $latestStateHash = null;

                        foreach ($docLogs as $log) {
                            $logUserId = $log['user_id'] !== null ? (int) $log['user_id'] : null;
                            $epoch = IntegrityManager::logTimestamp($log['created_at']);
                            $remarks = (string) ($log['remarks'] ?? '');
                            $isBadLog = false;

                            // Step 1: Re-derive the chain hash from the expected parent, not the stored previous_hash
                            $recalculatedHash = IntegrityManager::chainHash(
                                (int) $log['document_id'],
                                $logUserId,
                                $log['action'],
                                $epoch,
                                $expectedPreviousHash,
                                (string) $log['document_state_hash'],
                                $remarks,
                                (string) $log['signature']
                            );
                            if ($recalculatedHash !== $log['hash']) {
                                $invalidLogsCount++;
                                $isBadLog = true;
                            }

                            // Step 2: Verify the Ed25519 signature over the full signed message
                            $message = IntegrityManager::signedMessage(
                                (int) $log['document_id'],
                                $log['action'],
                                $epoch,
                                $expectedPreviousHash,
                                (string) $log['document_state_hash'],
                                $remarks
                            );
                            $verification = IntegrityManager::verifySignature($logUserId, $log['created_at'], $message, $log['signature']);
                            if (!$verification['valid']) {
                                $invalidSignaturesCount++;
                                $isBadLog = true;
                            }

                            // Record each failing log once, keeping the stored list bounded
                            if ($isBadLog && !isset($mismatchedIdsSet[$log['id']])) {
                                $mismatchedIdsSet[$log['id']] = true;
                                $mismatchedIdsCount++;
                                if ($mismatchedIdsCount <= self::MAX_TRACKED_MISMATCHES) {
                                    $mismatchedIdsList[] = $log['id'];
                                }
                            }

                            $expectedPreviousHash = $log['hash'];
                            $latestStateHash = $log['document_state_hash'];
                            $processedLogs++;
                        }

                        // Step 3: Compare the current live state against its last cryptographically sealed state
                        if ($latestStateHash) {
                            $currentStateHash = \App\Core\IntegrityManager::calculateStateHash($documentData);
                            
                            if ($currentStateHash !== $latestStateHash) {
                                // Fallback: Check JSON spacing artifact
                                $altDocumentData = $documentData;
                                if (is_string($altDocumentData['finalized_route'])) {
                                    $decoded = json_decode($altDocumentData['finalized_route'], true);
                                    if ($decoded !== null) {
                                        $altDocumentData['finalized_route'] = json_encode($decoded);
                                        $altStateHash = \App\Core\IntegrityManager::calculateStateHash($altDocumentData);
                                        if ($altStateHash === $latestStateHash) {
                                            $currentStateHash = $altStateHash;
                                        }
                                    }
                                }
                            }

                            if ($currentStateHash !== $latestStateHash) {
                                $liveStateErrorsCount++;
                                // Cap stored tracking codes to avoid an unbounded list in the results JSON
                                if (count($mismatchedDocumentTrackingCodes) < self::MAX_TRACKED_MISMATCHES) {
                                    $mismatchedDocumentTrackingCodes[] = $documentData['tracking_code'];
                                }
                                $documentObj = \App\Models\Document::findById($documentData['id']);
                                EventDispatcher::dispatch(new IntegrityCheckFailed($documentObj, 'Verification Scan'));
                            }
                        }
                        
                        $processedDocs++;
                        if ($processedDocs % 100 == 0) {
                            $percent = 5 + floor(($processedDocs / $totalDocuments) * 90);
                            $db->query("UPDATE integrity_checks SET progress = :p, updated_at = NOW() WHERE id = :id", [
                                'p' => $percent,
                                'id' => $this->integrityCheckId
                            ]);
                        }
                    } // End foreach docs
                } // End while true
            } // End if totalDocuments > 0

            // Use $mismatchedIdsCount (the true total) so the percentage stays accurate even when the stored list is capped
            $verifiedPercentage = ($totalLogs > 0) ? (($totalLogs - $mismatchedIdsCount) / $totalLogs) * 100 : 100;

            // Floor instead of round so a single failure can never be displayed as 100%
            $verifiedPercentage = floor($verifiedPercentage * 100) / 100;

            // Detect deleted chain heads against the newest signed checkpoint
            $checkpoint = (new ChainCheckpointJob())->verifyLatest();

            $results = [
                'verified_percentage' => $verifiedPercentage,
                'last_checked' => date('Y-m-d H:i:s'),
                'total_logs' => $totalLogs,
                'invalid_logs' => $invalidLogsCount,
                'invalid_signatures' => $invalidSignaturesCount,
                'mismatched_ids' => $mismatchedIdsList,
                'total_mismatched_ids' => $mismatchedIdsCount,
                'live_state_errors_count' => $liveStateErrorsCount,
                'mismatched_document_tracking_codes' => $mismatchedDocumentTrackingCodes,
                'checkpoint' => $checkpoint,
            ];

            if (!is_dir(BASE_PATH . '/cache')) {
                mkdir(BASE_PATH . '/cache', 0777, true);
            }
            file_put_contents(BASE_PATH . '/cache/integrity-check-result.json', json_encode($results));

            $db->query("UPDATE integrity_checks SET status = 'completed', progress = 100, results = :res, updated_at = NOW() WHERE id = :id", [
                'res' => json_encode($results),
                'id' => $this->integrityCheckId
            ]);

        } catch (\Throwable $e) {
            $db->query("UPDATE integrity_checks SET status = 'failed', error_message = :err, updated_at = NOW() WHERE id = :id", [
                'err' => $e->getMessage(),
                'id' => $this->integrityCheckId
            ]);
        }
    }
}
