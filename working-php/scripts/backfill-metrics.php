<?php
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';
use App\Core\Database;

echo "Starting Daily Department Metrics Backfill...\n";

$db = Database::getInstance();

// Clear existing metrics
$db->query("TRUNCATE TABLE daily_department_metrics");

// Get all departments
$departments = $db->query("SELECT id, name FROM departments")->fetchAll();
$deptMap = [];
foreach ($departments as $d) {
    $deptMap[$d['name']] = $d['id'];
}

// 1. Process "Received" events
echo "Processing received counts...\n";
$db->query("
    INSERT INTO daily_department_metrics (department_id, date, received_count, created_at, updated_at)
    SELECT d.id, DATE(dl.created_at), COUNT(DISTINCT dl.document_id), NOW(), NOW()
    FROM document_logs dl
    JOIN users u ON dl.user_id = u.id
    JOIN departments d ON u.department_id = d.id
    WHERE dl.action LIKE '%Received%' OR dl.action LIKE '%Scanned%' OR dl.action = 'Accepted and Document Routing finalized' OR dl.action = 'Ready for Releasing'
    GROUP BY d.id, DATE(dl.created_at)
    ON DUPLICATE KEY UPDATE received_count = VALUES(received_count), updated_at = NOW()
");

// 2. Process "Completed" events
echo "Processing completed counts...\n";
$db->query("
    INSERT INTO daily_department_metrics (department_id, date, processed_count, created_at, updated_at)
    SELECT d.id, DATE(dl.created_at), COUNT(DISTINCT dl.document_id), NOW(), NOW()
    FROM document_logs dl
    JOIN users u ON dl.user_id = u.id
    JOIN departments d ON u.department_id = d.id
    WHERE dl.action LIKE '%Complete%'
    GROUP BY d.id, DATE(dl.created_at)
    ON DUPLICATE KEY UPDATE processed_count = VALUES(processed_count), updated_at = NOW()
");

// 3. Process "Released" events
echo "Processing released counts...\n";
$db->query("
    INSERT INTO daily_department_metrics (department_id, date, released_count, created_at, updated_at)
    SELECT d.id, DATE(dl.created_at), COUNT(DISTINCT dl.document_id), NOW(), NOW()
    FROM document_logs dl
    JOIN users u ON dl.user_id = u.id
    JOIN departments d ON u.department_id = d.id
    WHERE dl.action LIKE '%Released%'
    GROUP BY d.id, DATE(dl.created_at)
    ON DUPLICATE KEY UPDATE released_count = VALUES(released_count), updated_at = NOW()
");

// 4. Processing Time (Rough Estimate for seeded data)
echo "Processing time estimations...\n";
$db->query("
    UPDATE daily_department_metrics m
    SET total_processing_seconds = processed_count * (FLOOR(RAND() * 3600) + 1800)
    WHERE processed_count > 0
");

echo "Backfill complete.\n";
