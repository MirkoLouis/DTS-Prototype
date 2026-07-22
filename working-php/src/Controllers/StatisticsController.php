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
            $filterPurpose = $_GET['purpose'] ?? null;
            $filterSubmitter = $_GET['submitter'] ?? null;
            $searchTerm = $_GET['search'] ?? null;
            $filterDate = $_GET['date'] ?? null;
            

            $where = ["d.status = 'completed'", "d.released_by_user_id > 0"];
            $params = [];

            $join = "INNER JOIN users u ON d.released_by_user_id = u.id AND u.department_id = :dept_id
                     LEFT JOIN purposes p ON d.purpose_id = p.id";
            $params[':dept_id'] = $departmentId;

            if ($filterDate) {
                $where[] = "d.released_at >= :date_start AND d.released_at <= :date_end";
                $params[':date_start'] = $filterDate . ' 00:00:00';
                $params[':date_end'] = $filterDate . ' 23:59:59';
            }
            if ($searchTerm) {
                $searchTerm = trim($searchTerm);
                if (preg_match('/^DEPED-/i', $searchTerm)) {
                    $where[] = "d.tracking_code LIKE :search";
                    $params[':search'] = $searchTerm . '%';
                } else {
                    $where[] = "(d.tracking_code LIKE :search OR json_unquote(json_extract(d.guest_info, '$.name')) LIKE :search2)";
                    $params[':search'] = '%' . $searchTerm . '%';
                    $params[':search2'] = '%' . $searchTerm . '%';
                }
            }
            if ($filterPurpose && $filterPurpose !== 'all') {
                $where[] = "p.name = :purpose";
                $params[':purpose'] = $filterPurpose;
            }
            if ($filterSubmitter) {
                $where[] = "LOWER(json_unquote(json_extract(d.guest_info, '$.name'))) LIKE :submitter";
                $params[':submitter'] = '%' . strtolower($filterSubmitter) . '%';
            }

            $whereSql = implode(' AND ', $where);

            $cursor = $_GET['cursor'] ?? null;
            
            $cacheKey = 'count_stats_controller_' . md5(json_encode($params) . $whereSql);
            $totalItems = Cache::remember($cacheKey, 300, function() use ($db, $join, $whereSql, $params) {
                $countSql = "SELECT COUNT(*) as total FROM documents d {$join} WHERE {$whereSql}";
                $countStmt = $db->query($countSql, $params);
                return $countStmt->fetch()['total'] ?? 0;
            });

            if ($cursor) {
                $parts = explode('_', $cursor);
                if (count($parts) == 2) {
                    $whereSql .= " AND (d.released_at < :c_time1 OR (d.released_at = :c_time2 AND d.id < :c_id))";
                    $params[':c_time1'] = $parts[0];
                    $params[':c_time2'] = $parts[0];
                    $params[':c_id'] = $parts[1];
                }
            }

            $perPage = 10;
            $limit = $perPage + 1;

            $sql = "SELECT d.*, p.name as purpose_name 
                    FROM documents d 
                    {$join} 
                    WHERE {$whereSql} 
                    ORDER BY d.released_at DESC, d.id DESC 
                    LIMIT {$limit}";
            
            $stmt = $db->query($sql, $params);
            $documents = $stmt->fetchAll();
            
            $nextCursor = null;
            if (count($documents) > $perPage) {
                $nextCursor = $documents[$perPage - 1]['released_at'] . '_' . $documents[$perPage - 1]['id'];
            }
            
            $paginator = new \App\Utils\CursorPaginator($documents, $perPage, $nextCursor, $totalItems, '?' . http_build_query(array_diff_key($_GET, ['cursor' => ''])));
            $documents = $paginator->getItems();
            
            foreach ($documents as &$doc) {
                $doc['guest_info'] = $doc['guest_info'] ? json_decode($doc['guest_info'], true) : [];
            }
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
        $controller = new DashboardController();
        return $controller->getThroughputData();
    }

    public function getCurrentLoadData()
    {
        $controller = new DashboardController();
        return $controller->getCurrentLoadData();
    }

    public function getAverageProcessingTimeData()
    {
        $controller = new DashboardController();
        return $controller->getAverageProcessingTimeData();
    }

    private function getAllPurposes()
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT id, name FROM purposes ORDER BY name ASC");
        return $stmt->fetchAll();
    }
}
