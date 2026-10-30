<?php

namespace App\Controllers;

use App\Common\Database\Database;
use App\Modules\Integrity\DigitalSignatureService;
use App\Modules\Integrity\HashChainService;

class SystemHealthController
{
    public function index()
    {
        $db = Database::getInstance();
        
        $integrityCheckResult = [
            'verified_percentage' => 'N/A',
            'last_checked' => 'Never',
            'mismatched_ids' => [],
        ];

        if (file_exists(\App\Modules\Integrity\Jobs\IntegrityCheckJob::resultPath())) {
            $integrityCheckResult = json_decode(file_get_contents(\App\Modules\Integrity\Jobs\IntegrityCheckJob::resultPath()), true);
        }

        $issues = [];

        // Simplified for raw PHP port
        if (!empty($integrityCheckResult['mismatched_ids'])) {
            // Prevent memory exhaustion on massive failures by limiting to first 100
            $limitedIds = array_slice($integrityCheckResult['mismatched_ids'], 0, 100);
            $ids = implode(',', array_map('intval', $limitedIds));
            $stmt = $db->query("SELECT dl.*, d.tracking_code, d.title as document_title, u.name as user_name FROM document_logs dl LEFT JOIN documents d ON dl.document_id = d.id LEFT JOIN users u ON dl.user_id = u.id WHERE dl.id IN ($ids)");
            $mismatchedLogs = $stmt->fetchAll();
            
            foreach ($mismatchedLogs as $log) {
                $issues[] = [
                    'type' => 'Log Chain Corruption',
                    'tracking_code' => $log['tracking_code'],
                    'title' => $log['document_title'] ?? 'Unknown',
                    'description' => "Action: {$log['action']}",
                    'log_id' => $log['id'],
                    'hash' => $log['hash'],
                    'user_name' => $log['user_name']
                ];
            }
        }

        if (!empty($integrityCheckResult['mismatched_document_tracking_codes'])) {
            $limitedCodes = array_slice($integrityCheckResult['mismatched_document_tracking_codes'], 0, 100);
            $codes = array_map(function($c) { return "'".addslashes($c)."'"; }, $limitedCodes);
            $codesStr = implode(',', $codes);
            $stmt = $db->query("SELECT * FROM documents WHERE tracking_code IN ($codesStr)");
            $mismatchedDocuments = $stmt->fetchAll();
            
            foreach ($mismatchedDocuments as $doc) {
                $issues[] = [
                    'type' => 'Live State Tampering',
                    'tracking_code' => $doc['tracking_code'],
                    'title' => $doc['title'],
                    'description' => "Status: {$doc['status']}",
                    'log_id' => null,
                    'hash' => null,
                    'user_name' => null
                ];
            }
        }

        $page = $_GET['page'] ?? 1;
        $totalItems = count($issues);
        $paginator = new \App\Common\Utils\Paginator($totalItems, 10, $page, '?page=(:num)&' . http_build_query(array_diff_key($_GET, ['page' => ''])));
        
        $paginatedIssues = array_slice($issues, $paginator->getOffset(), $paginator->getLimit());

        $fjPage = $_GET['fj_page'] ?? 1;
        $appHealthMetrics = $this->getApplicationHealthMetrics($fjPage);

        require BASE_PATH . '/src/Views/admin/system-overview.php';
    }

    private function getApplicationHealthMetrics($fjPage = 1)
    {
        $db = Database::getInstance();
        
        // 1. Average Processing Time (Removed as requested)

        // 2. Failed Jobs
        $failedJobsCount = $db->query("SELECT COUNT(*) as count FROM failed_jobs")->fetch()['count'];
        $fjPaginator = new \App\Common\Utils\Paginator($failedJobsCount, 10, $fjPage, '?fj_page=(:num)&' . http_build_query(array_diff_key($_GET, ['fj_page' => ''])));
        
        $limit = $fjPaginator->getLimit();
        $offset = $fjPaginator->getOffset();
        $failedJobs = $db->query("SELECT * FROM failed_jobs ORDER BY failed_at DESC LIMIT $limit OFFSET $offset")->fetchAll();

        return [
            'failed_jobs_count' => $failedJobsCount,
            'failed_jobs' => $failedJobs,
            'failed_jobs_paginator' => $fjPaginator,
            'cache_status' => $this->checkCacheHealth(),
        ];
    }

    private function checkCacheHealth(): bool
    {
        try {
            \App\Common\Cache\Cache::put('system_health_check', 'ok', 10);
            $val = \App\Common\Cache\Cache::get('system_health_check');
            $isWritable = is_writable(BASE_PATH . '/cache');
            return ($val === 'ok' && $isWritable);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function deleteFailedJob($id)
    {
        $db = Database::getInstance();
        $db->query("DELETE FROM failed_jobs WHERE id = :id", ['id' => $id]);
        header('Location: /system-health');
        exit;
    }

    public function deleteAllFailedJobs()
    {
        $db = Database::getInstance();
        $db->query("TRUNCATE TABLE failed_jobs");
        header('Location: /system-health');
        exit;
    }

    public function debugLog($id)
    {
        $db = Database::getInstance();
        $log = $db->query("SELECT * FROM document_logs WHERE id = :id", ['id' => $id])->fetch();
        
        if (!$log) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Log not found']);
            exit;
        }

        $lastLog = $db->query("SELECT * FROM document_logs WHERE document_id = :doc_id AND id < :id ORDER BY id DESC LIMIT 1", [
            'doc_id' => $log['document_id'],
            'id' => $log['id']
        ])->fetch();

        // Rebuild the block's inputs exactly as the auditor does (format v2)
        $previousHash = $lastLog ? $lastLog['hash'] : HashChainService::GENESIS_HASH;
        $userId = $log['user_id'] !== null ? (int) $log['user_id'] : null;
        $epoch = HashChainService::logTimestamp($log['created_at']);
        $remarks = (string) ($log['remarks'] ?? '');

        $hashInput = HashChainService::chainHashInput((int) $log['document_id'], $userId, $log['action'], $epoch, $previousHash, (string) $log['document_state_hash'], $remarks, (string) $log['signature']);
        $recalculatedHash = HashChainService::chainHash((int) $log['document_id'], $userId, $log['action'], $epoch, $previousHash, (string) $log['document_state_hash'], $remarks, (string) $log['signature']);

        // Verify the signature too, so the debugger explains signature failures and not only hash failures
        $message = HashChainService::signedMessage((int) $log['document_id'], $log['action'], $epoch, $previousHash, (string) $log['document_state_hash'], $remarks);
        $verification = DigitalSignatureService::verifySignature($userId, $log['created_at'], $message, $log['signature']);

        header('Content-Type: application/json');
        echo json_encode([
            'stored_hash' => $log['hash'],
            'recalculated_hash' => $recalculatedHash,
            'match' => $log['hash'] === $recalculatedHash,
            'components' => [
                'document_id' => $log['document_id'],
                'user_id' => $log['user_id'] ?? 'system',
                'action' => $log['action'],
                'created_at' => $log['created_at'],
                'epoch (hashed)' => $epoch,
                'previous_hash' => $previousHash,
                'document_state_hash' => $log['document_state_hash'],
                'remarks' => $remarks,
                'signature' => $log['signature'],
                'signature_valid' => $verification['valid'] ? 'yes' : 'NO',
                'verified_with' => $verification['key_source'],
            ],
            'raw_data_string' => json_encode($hashInput, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ]);
        exit;
    }

    public function runIntegrityCheck()
    {
        $db = Database::getInstance();
        $id = uniqid(); // Simulating UUID

        $db->query("INSERT INTO integrity_checks (id, user_id, status, progress, created_at, updated_at) VALUES (:id, :user_id, 'queued', 0, NOW(), NOW())", [
            'id' => $id,
            'user_id' => $_SESSION['user_id'] ?? null
        ]);

        // Dispatch job by inserting into jobs table
        $payload = json_encode([
            'class' => \App\Modules\Integrity\Jobs\IntegrityCheckJob::class,
            'data' => [$id]
        ]);
        
        $db->query("INSERT INTO jobs (queue, payload, attempts, available_at, created_at) VALUES ('default', :payload, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())", [
            'payload' => $payload
        ]);

        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success', 
            'message' => 'Integrity check started.',
            'job_id' => $id
        ]);
        exit;
    }

    public function getIntegrityCheckStatus($jobId)
    {
        $db = Database::getInstance();
        $check = $db->query("SELECT * FROM integrity_checks WHERE id = :id", ['id' => $jobId])->fetch();
        
        header('Content-Type: application/json');
        echo json_encode($check);
        exit;
    }

    public function cancelIntegrityCheck($jobId)
    {
        $db = Database::getInstance();
        $db->query("UPDATE integrity_checks SET status = 'cancelled' WHERE id = :id AND status IN ('queued', 'processing')", ['id' => $jobId]);
        
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        exit;
    }

    public function getIntegrityCheckResults()
    {
        $result = [
            'verified_percentage' => 'N/A',
            'last_checked' => 'Never',
        ];

        if (file_exists(\App\Modules\Integrity\Jobs\IntegrityCheckJob::resultPath())) {
            $result = json_decode(file_get_contents(\App\Modules\Integrity\Jobs\IntegrityCheckJob::resultPath()), true);
        }

        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }

    public function getDbPerformanceData()
    {
        $service = new \App\Services\DatabasePerformanceService();
        $period = $_GET['period'] ?? 'daily';
        
        header('Content-Type: application/json');
        echo json_encode($service->getChartData($period));
        exit;
    }

    public function exportDbPerformanceMetrics()
    {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="database-performance-metrics-' . date('Y-m-d-His') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['id', 'connections', 'avg_query_time_ms', 'slow_queries', 'created_at']);

        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM database_metrics ORDER BY id ASC");
        
        while ($row = $stmt->fetch()) {
            fputcsv($out, [
                $row['id'],
                $row['connections'],
                $row['avg_query_time_ms'],
                $row['slow_queries'],
                $row['created_at'],
            ]);
        }
        fclose($out);
        exit;
    }

    public function freeze($tracking_code)
    {
        $currentUser = \App\Modules\Users\UserModel::findById($_SESSION['user_id'] ?? 0);
        $submittedPin = $_POST['pin'] ?? '';
        $pin = \App\Modules\Users\SecurityPinCacheService::resolvePin($submittedPin);

        if (empty($pin)) {
            $_SESSION['error'] = "Security PIN is required.";
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? "/documents/{$tracking_code}"));
            exit;
        }

        try {
            $workflow = new \App\Modules\Documents\DocumentWorkflowService();
            $workflow->freezeDocument($tracking_code, $currentUser, $pin);
            
            $_SESSION['success'] = 'Document has been frozen successfully.';
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Action Denied')) {
                $_SESSION['console_error'] = $e->getMessage();
            } else {
                $_SESSION['error'] = $e->getMessage();
            }
        }
        
        $referer = $_SERVER['HTTP_REFERER'] ?? "/documents/{$tracking_code}";
        header("Location: $referer");
        exit;
    }

    public function unfreeze($tracking_code)
    {
        $currentUser = \App\Modules\Users\UserModel::findById($_SESSION['user_id'] ?? 0);
        $submittedPin = $_POST['pin'] ?? '';
        $pin = \App\Modules\Users\SecurityPinCacheService::resolvePin($submittedPin);

        if (empty($pin)) {
            $_SESSION['error'] = "Security PIN is required.";
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? "/documents/{$tracking_code}"));
            exit;
        }

        try {
            $workflow = new \App\Modules\Documents\DocumentWorkflowService();
            $previousStatus = $workflow->unfreezeDocument($tracking_code, $currentUser, $pin);
            
            $_SESSION['success'] = "Document has been unfrozen and restored to " . ucfirst($previousStatus) . ".";
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Action Denied')) {
                $_SESSION['console_error'] = $e->getMessage();
            } else {
                $_SESSION['error'] = $e->getMessage();
            }
        }
        
        $referer = $_SERVER['HTTP_REFERER'] ?? "/documents/{$tracking_code}";
        header("Location: $referer");
        exit;
    }

    public function freezeAll()
    {
        $currentUser = \App\Modules\Users\UserModel::findById($_SESSION['user_id'] ?? 0);
        $submittedPin = $_POST['pin'] ?? '';
        $pin = \App\Modules\Users\SecurityPinCacheService::resolvePin($submittedPin);

        if (empty($pin)) {
            $_SESSION['error'] = "Security PIN is required.";
            header("Location: /system-overview");
            exit;
        }

        $db = Database::getInstance();
        $workflow = new \App\Modules\Documents\DocumentWorkflowService();
        
        $integrityCheckResult = [];
        if (file_exists(\App\Modules\Integrity\Jobs\IntegrityCheckJob::resultPath())) {
            $integrityCheckResult = json_decode(file_get_contents(\App\Modules\Integrity\Jobs\IntegrityCheckJob::resultPath()), true);
        }

        $trackingCodesToFreeze = [];

        // Collect documents from live state tampering
        if (!empty($integrityCheckResult['mismatched_document_tracking_codes'])) {
            foreach ($integrityCheckResult['mismatched_document_tracking_codes'] as $code) {
                $trackingCodesToFreeze[$code] = true;
            }
        }

        // Collect documents from hash chain corruption
        if (!empty($integrityCheckResult['mismatched_ids'])) {
            $limitedIds = array_slice($integrityCheckResult['mismatched_ids'], 0, 100);
            $ids = implode(',', array_map('intval', $limitedIds));
            if ($ids) {
                $stmt = $db->query("SELECT d.tracking_code FROM document_logs dl JOIN documents d ON dl.document_id = d.id WHERE dl.id IN ($ids)");
                while ($row = $stmt->fetch()) {
                    if ($row['tracking_code']) {
                        $trackingCodesToFreeze[$row['tracking_code']] = true;
                    }
                }
            }
        }

        $frozenCount = 0;
        $throttled = false;
        foreach (array_keys($trackingCodesToFreeze) as $code) {
            try {
                $workflow->freezeDocument($code, $currentUser, $pin);
                $frozenCount++;
            } catch (\Exception $e) {
                // A PIN timeout applies to every remaining document, so stop and report it
                if ($e->getMessage() === \App\Core\AttemptThrottle::MESSAGE) {
                    $throttled = true;
                    break;
                }
                // Ignore if it's already frozen or if permission issues arise
            }
        }

        // Surface a PIN timeout instead of a misleading success count
        if ($throttled) {
            $_SESSION['error'] = \App\Core\AttemptThrottle::MESSAGE;
            header("Location: /system-overview");
            exit;
        }

        $_SESSION['success'] = "Successfully frozen $frozenCount documents with integrity issues.";
        header("Location: /system-overview");
        exit;
    }

    public function autoResolve($tracking_code)
    {
        $currentUser = \App\Modules\Users\UserModel::findById($_SESSION['user_id'] ?? 0);
        $submittedPin = $_POST['pin'] ?? '';
        $pin = \App\Modules\Users\SecurityPinCacheService::resolvePin($submittedPin);

        if (empty($pin)) {
            $_SESSION['error'] = "Security PIN is required.";
            header('Location: /system-overview');
            exit;
        }

        try {
            $workflow = new \App\Modules\Documents\DocumentWorkflowService();
            $previousStatus = $workflow->autoResolveDocument($tracking_code, $currentUser, $pin);
            $_SESSION['success'] = "Document auto-resolved successfully. Restored from snapshot and unfrozen (Status: " . ucfirst($previousStatus) . ").";
        } catch (\Exception $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        header('Location: /system-overview');
        exit;
    }

    public function clearPersonalCache()
    {
        $userId = $_SESSION['user_id'] ?? 'guest';
        $prefix = "cache_" . ($userId === 'guest' ? 'guest' : "user_{$userId}");
        
        $cacheDir = \App\Common\Middleware\CacheMiddleware::cacheDir() . '/';
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . $prefix . '_*.html');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        
        $_SESSION['success'] = "Your personalized cache has been cleared successfully.";
        
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        header("Location: " . $referer);
        exit;
    }
}
