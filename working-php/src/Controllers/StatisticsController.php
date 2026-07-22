<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Cache;

class StatisticsController
{
    public function index()
    {
        $db = Database::getInstance();
        $userRole = $_SESSION['role'] ?? '';
        
        $viewData = [];

        if ($userRole === 'officer' || $userRole === 'staff') {
            $departmentId = $_SESSION['department_id'] ?? 0;
            $queryService = new \App\Services\DocumentQueryService();

            [$documents, $paginator] = $queryService->getPaginatedStatistics($departmentId, $_GET);

            $viewData['releasedDocuments'] = $documents;
            $viewData['paginator'] = $paginator;
            $viewData['purposes'] = $this->getAllPurposes();
            $viewData['activeFilters'] = ['date', 'purpose', 'submitter', 'search'];
            
            $pastReports = $db->query("SELECT * FROM report_jobs WHERE user_id = :uid AND status = 'completed' ORDER BY created_at DESC", ['uid' => $_SESSION['user_id']])->fetchAll();
            $viewData['pastReports'] = $pastReports;
        }
        
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            extract($viewData);
            require BASE_PATH . '/src/Views/officer/partials/released-documents-table.php';
            exit;
        }

        extract($viewData);
        require BASE_PATH . '/src/Views/general/statistics.php';
    }

    public function generateReport()
    {
        $db = Database::getInstance();
        $jobId = uniqid();
        $userId = $_SESSION['user_id'];
        
        $db->query("INSERT INTO report_jobs (id, user_id, status, progress, total_documents, created_at, updated_at) VALUES (:id, :uid, 'queued', 0, 0, NOW(), NOW())", [
            'id' => $jobId,
            'uid' => $userId
        ]);

        $payload = json_encode(['class' => \App\Jobs\GenerateReportJob::class, 'data' => [$jobId, $userId, $_POST]]);
        $db->query("INSERT INTO jobs (queue, payload, attempts, available_at, created_at) VALUES ('default', :payload, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())", [
            'payload' => $payload
        ]);

        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'message' => 'Report generation started.', 'job_id' => $jobId]);
        exit;
    }

    public function getReportStatus()
    {
        $jobId = $_GET['job_id'] ?? null;
        $db = Database::getInstance();
        $status = $db->query("SELECT * FROM report_jobs WHERE id = :id", ['id' => $jobId])->fetch();
        
        header('Content-Type: application/json');
        echo json_encode($status);
        exit;
    }

    public function cancelReport()
    {
        $jobId = $_POST['job_id'] ?? null;
        $db = Database::getInstance();
        $db->query("UPDATE report_jobs SET status = 'cancelled' WHERE id = :id AND status IN ('queued', 'processing')", ['id' => $jobId]);
        
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        exit;
    }

    public function downloadReport($jobId)
    {
        $db = Database::getInstance();
        $job = $db->query("SELECT * FROM report_jobs WHERE id = :id", ['id' => $jobId])->fetch();
        
        if ($job && $job['file_path']) {
            $filePath = BASE_PATH . '/storage/app/' . $job['file_path'];
            if (!file_exists($filePath)) {
                // Fallback in case old jobs were saved directly to BASE_PATH (if any)
                $filePath = BASE_PATH . '/' . $job['file_path'];
            }
            if (file_exists($filePath)) {
                $ext = pathinfo($filePath, PATHINFO_EXTENSION);
                header('Content-Description: File Transfer');
                if ($ext === 'xlsx') {
                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                } else {
                    header('Content-Type: application/csv');
                }
                header('Content-Disposition: attachment; filename="'.basename($filePath).'"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($filePath));
                readfile($filePath);
                exit;
            }
        }
        
        header('Location: /statistics');
        exit;
    }

    public function getReportCount()
    {
        $db = Database::getInstance();
        $count = $db->query("SELECT COUNT(*) as count FROM documents WHERE status = 'completed'")->fetch()['count'];
        header('Content-Type: application/json');
        echo json_encode(['count' => $count]);
        exit;
    }

    public function getThroughputData()
    {
        $period = $_GET['period'] ?? 'daily';
        $departmentId = $_SESSION['department_id'] ?? 0;
        
        $service = new \App\Services\DepartmentAnalyticsService();
        $data = $service->getMetricTimeSeries($departmentId, $period, 'processed_count + released_count');

        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    public function getCurrentLoadData()
    {
        $period = $_GET['period'] ?? 'daily';
        $departmentId = $_SESSION['department_id'] ?? 0;
        
        $service = new \App\Services\DepartmentAnalyticsService();
        $data = $service->getMetricTimeSeries($departmentId, $period, 'received_count');

        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    public function getAverageProcessingTimeData()
    {
        $period = $_GET['period'] ?? 'daily';
        $departmentId = $_SESSION['department_id'] ?? 0;
        
        $service = new \App\Services\DepartmentAnalyticsService();
        $data = $service->getAverageProcessingTime($departmentId, $period);

        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    private function getAllPurposes()
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT id, name FROM purposes ORDER BY name ASC");
        return $stmt->fetchAll();
    }
}
