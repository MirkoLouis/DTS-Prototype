<?php
define('BASE_PATH', dirname(__DIR__));
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

try {
    $db = Database::getInstance();
    
    echo "Truncating daily_department_metrics...\n";
    $db->query("TRUNCATE TABLE daily_department_metrics");

    echo "Reconstructing daily_department_metrics table from logs...\n";
    $logs = $db->query("SELECT dl.*, u.department_id FROM document_logs dl LEFT JOIN users u ON dl.user_id = u.id ORDER BY dl.document_id ASC, dl.created_at ASC")->fetchAll();

    $metricsBuffer = [];
    $receivedTimes = [];

    foreach ($logs as $log) {
        if (!$log['department_id']) continue;

        $deptId = $log['department_id'];
        // Ensure date is properly extracted even if datetime has time
        $date = substr($log['created_at'], 0, 10);
        $key = "{$deptId}_{$date}";

        if (!isset($metricsBuffer[$key])) {
            $metricsBuffer[$key] = [
                'department_id' => $deptId,
                'date' => $date,
                'received_count' => 0,
                'processed_count' => 0,
                'released_count' => 0,
                'total_processing_seconds' => 0,
            ];
        }

        if ($log['action'] === 'Received' || $log['action'] === 'Ready for Releasing') {
            $receivedTimes[$log['document_id']][$log['user_id']] = strtotime($log['created_at']);
            $metricsBuffer[$key]['received_count']++;
        }

        if ($log['action'] === 'Processing Complete') {
            $seconds = 0;
            if (isset($receivedTimes[$log['document_id']][$log['user_id']])) {
                $seconds = abs(strtotime($log['created_at']) - $receivedTimes[$log['document_id']][$log['user_id']]);
                unset($receivedTimes[$log['document_id']][$log['user_id']]);
            }
            
            $metricsBuffer[$key]['processed_count']++;
            $metricsBuffer[$key]['total_processing_seconds'] += $seconds;
        }

        if ($log['action'] === 'Document Released') {
            $seconds = 0;
            if (isset($receivedTimes[$log['document_id']][$log['user_id']])) {
                $seconds = abs(strtotime($log['created_at']) - $receivedTimes[$log['document_id']][$log['user_id']]);
                unset($receivedTimes[$log['document_id']][$log['user_id']]);
            }
            
            $metricsBuffer[$key]['released_count']++;
            $metricsBuffer[$key]['total_processing_seconds'] += $seconds;
        }
    }

    $chunks = array_chunk($metricsBuffer, 500);
    foreach ($chunks as $chunk) {
        foreach ($chunk as $data) {
            $db->query("INSERT INTO daily_department_metrics (department_id, date, received_count, processed_count, released_count, total_processing_seconds, created_at, updated_at) 
                        VALUES (:dept_id, :date, :received, :processed, :released, :seconds, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE 
                            received_count = received_count + :received2,
                            processed_count = processed_count + :processed2,
                            released_count = released_count + :released2,
                            total_processing_seconds = total_processing_seconds + :seconds2,
                            updated_at = NOW()", [
                'dept_id' => $data['department_id'],
                'date' => $data['date'],
                'received' => $data['received_count'],
                'processed' => $data['processed_count'],
                'released' => $data['released_count'],
                'seconds' => $data['total_processing_seconds'],
                'received2' => $data['received_count'],
                'processed2' => $data['processed_count'],
                'released2' => $data['released_count'],
                'seconds2' => $data['total_processing_seconds']
            ]);
        }
    }

    echo "Backfill complete!\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
