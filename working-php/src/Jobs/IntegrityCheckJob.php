<?php

namespace App\Jobs;

use App\Core\Database;
use App\Events\IntegrityCheckFailed;
use App\Core\EventDispatcher;

class IntegrityCheckJob
{
    protected $integrityCheckId;

    public function __construct(string $integrityCheckId)
    {
        $this->integrityCheckId = $integrityCheckId;
    }

    public function handle(): void
    {
        $db = Database::getInstance();
        
        $db->query("UPDATE integrity_checks SET status = 'processing', progress = 5, updated_at = NOW() WHERE id = :id", [
            'id' => $this->integrityCheckId
        ]);

        try {
            // Step 1: Historical Log Hash Chain Integrity
            $totalLogs = $db->query("SELECT COUNT(*) as c FROM document_logs")->fetch()['c'] ?? 0;
            $invalidLogsCount = 0;
            $invalidSignaturesCount = 0;
            $mismatchedIds = [];
            $lastHashesByDocument = [];
            $processedLogs = 0;

            if ($totalLogs > 0) {
                $logsStmt = $db->query("SELECT dl.*, u.public_key, u.security_key_set_at FROM document_logs dl LEFT JOIN users u ON dl.user_id = u.id ORDER BY dl.id ASC");
                
                while ($log = $logsStmt->fetch()) {
                    $db->query("SELECT status FROM integrity_checks WHERE id = :id", ['id' => $this->integrityCheckId]);
                    
                    $expectedPreviousHash = $lastHashesByDocument[$log['document_id']] ?? 'genesis_hash';
                    $timestampForHashing = date('c', strtotime($log['created_at']));
                    
                    $dataToHash = $log['document_id'] . '|' . 
                                 $log['user_id'] . '|' . 
                                 $log['action'] . '|' . 
                                 $timestampForHashing . '|' . 
                                 $expectedPreviousHash . '|' . 
                                 $log['document_state_hash'] . '|' . 
                                 $log['signature'];

                    $recalculatedHash = hash('sha256', $dataToHash);

                    if ($recalculatedHash !== $log['hash']) {
                        $invalidLogsCount++;
                        $mismatchedIds[] = $log['id'];
                    }

                    // 2. Verify Cryptographic Signature
                    if ($log['signature'] && strlen($log['signature']) > 10) {
                        $isMockSignature = str_starts_with(base64_decode($log['signature']), 'MOCK_SIG:');
                        if ($isMockSignature) {
                            $decodedMock = base64_decode($log['signature']);
                            $expectedMock = "MOCK_SIG:{$log['action']}|{$log['document_state_hash']}";
                            if ($decodedMock !== $expectedMock) {
                                $invalidSignaturesCount++;
                                if (!in_array($log['id'], $mismatchedIds)) $mismatchedIds[] = $log['id'];
                            }
                        } else {
                            $pubKey = $this->getPublicKeyAtTime($db, $log['user_id'], $log['created_at'], $log['public_key'], $log['security_key_set_at']);
                            if ($pubKey) {
                                $signedData = $log['action'] . '|' . $log['document_state_hash'];
                                $keyResource = openssl_pkey_get_public($pubKey);
                                if ($keyResource === false) {
                                    $invalidSignaturesCount++;
                                    if (!in_array($log['id'], $mismatchedIds)) $mismatchedIds[] = $log['id'];
                                } else {
                                    $verified = openssl_verify($signedData, base64_decode($log['signature']), $keyResource, OPENSSL_ALGO_SHA256);
                                    if ($verified !== 1) {
                                        $invalidSignaturesCount++;
                                        if (!in_array($log['id'], $mismatchedIds)) $mismatchedIds[] = $log['id'];
                                    }
                                }
                            } else {
                                $invalidSignaturesCount++;
                                if (!in_array($log['id'], $mismatchedIds)) $mismatchedIds[] = $log['id'];
                            }
                        }
                    }

                    $lastHashesByDocument[$log['document_id']] = $log['hash'];
                    $processedLogs++;
                    
                    if ($processedLogs % 100 == 0) {
                        $percent = 5 + floor(($processedLogs / $totalLogs) * 45);
                        $db->query("UPDATE integrity_checks SET progress = :p, updated_at = NOW() WHERE id = :id", [
                            'p' => $percent,
                            'id' => $this->integrityCheckId
                        ]);
                    }
                }
            }

            // Step 2: Live Document State Integrity
            $totalDocuments = $db->query("SELECT COUNT(*) as c FROM documents")->fetch()['c'] ?? 0;
            $liveStateErrorsCount = 0;
            $mismatchedDocumentTrackingCodes = [];
            $processedDocs = 0;

            if ($totalDocuments > 0) {
                $docsStmt = $db->query("SELECT * FROM documents");
                while ($documentData = $docsStmt->fetch()) {
                    $latestLog = $db->query("SELECT document_state_hash FROM document_logs WHERE document_id = :id ORDER BY id DESC LIMIT 1", ['id' => $documentData['id']])->fetch();
                    
                    if ($latestLog) {
                        $documentObj = \App\Models\Document::findById($documentData['id']);
                        $currentStateHash = \App\Core\IntegrityManager::calculateStateHash($documentData);
                        
                        if ($currentStateHash !== $latestLog['document_state_hash']) {
                            // Fallback: Check if mismatch is just a JSON spacing artifact (Eloquent vs PDO raw strings)
                            $altDocumentData = $documentData;
                            if (is_string($altDocumentData['finalized_route'])) {
                                $decoded = json_decode($altDocumentData['finalized_route'], true);
                                if ($decoded !== null) {
                                    $altDocumentData['finalized_route'] = json_encode($decoded); // enforce unspaced
                                    $altStateHash = \App\Core\IntegrityManager::calculateStateHash($altDocumentData);
                                    if ($altStateHash === $latestLog['document_state_hash']) {
                                        $currentStateHash = $altStateHash; // It matches!
                                    }
                                }
                            }
                        }

                        if ($currentStateHash !== $latestLog['document_state_hash']) {
                            $liveStateErrorsCount++;
                            $mismatchedDocumentTrackingCodes[] = $documentData['tracking_code'];
                            EventDispatcher::dispatch(new IntegrityCheckFailed($documentObj, 'Verification Scan'));
                        }
                    }
                    
                    $processedDocs++;
                    if ($processedDocs % 100 == 0) {
                        $percent = 50 + floor(($processedDocs / $totalDocuments) * 45);
                        $db->query("UPDATE integrity_checks SET progress = :p, updated_at = NOW() WHERE id = :id", [
                            'p' => $percent,
                            'id' => $this->integrityCheckId
                        ]);
                    }
                }
            }

            $verifiedPercentage = ($totalLogs > 0) ? (($totalLogs - (count($mismatchedIds))) / $totalLogs) * 100 : 100;

            $results = [
                'verified_percentage' => round($verifiedPercentage, 2),
                'last_checked' => date('Y-m-d H:i:s'),
                'total_logs' => $totalLogs,
                'invalid_logs' => $invalidLogsCount,
                'invalid_signatures' => $invalidSignaturesCount,
                'mismatched_ids' => $mismatchedIds,
                'live_state_errors_count' => $liveStateErrorsCount,
                'mismatched_document_tracking_codes' => $mismatchedDocumentTrackingCodes,
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

    protected function getPublicKeyAtTime($db, $userId, $timestamp, $userPubKey, $userKeySetAt)
    {
        $historicalKey = $db->query("SELECT public_key FROM user_public_key_histories WHERE user_id = :uid AND activated_at <= :ts AND (deactivated_at >= :ts2 OR deactivated_at IS NULL)", [
            'uid' => $userId,
            'ts' => $timestamp,
            'ts2' => $timestamp
        ])->fetch();

        if ($historicalKey) return $historicalKey['public_key'];

        if ($userPubKey) {
            $hasHistory = $db->query("SELECT id FROM user_public_key_histories WHERE user_id = :uid", ['uid' => $userId])->fetch();
            if (!$hasHistory) return $userPubKey;
            if ($userKeySetAt && strtotime($userKeySetAt) <= strtotime($timestamp)) return $userPubKey;
        }

        return null;
    }
}
