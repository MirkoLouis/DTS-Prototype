<?php

namespace App\Controllers;

use App\Core\Database;

class DashboardController
{
    /**
     * Admin Dashboard - Shows the status distribution chart.
     */
    public function adminDashboard()
    {
        $db = Database::getInstance();
        
        // Fetch some basic stats for the dashboard
        $stats = [];
        $stats['total_documents'] = $db->query("SELECT COUNT(*) as count FROM documents")->fetch()['count'];
        $stats['pending_documents'] = $db->query("SELECT COUNT(*) as count FROM documents WHERE status = 'pending'")->fetch()['count'];
        $stats['completed_documents'] = $db->query("SELECT COUNT(*) as count FROM documents WHERE status = 'completed'")->fetch()['count'];
        
        // Fetch status distribution for the chart
        $sql = "SELECT status, COUNT(*) as count FROM documents GROUP BY status";
        $stmt = $db->query($sql);
        $results = $stmt->fetchAll();
        
        $labels = [];
        $data = [];
        $backgroundColors = [];
        
        $colorMap = [
            'pending' => '#f97316', 
            'in_transit' => '#3b82f6', 
            'processing' => '#eab308', 
            'ready_for_release' => '#84cc16', 
            'completed' => '#22c55e', 
            'declined' => '#ef4444', 
            'frozen' => '#64748b', 
        ];

        foreach ($results as $row) {
            $status = $row['status'];
            $labels[] = ucfirst(str_replace('_', ' ', $status));
            $data[] = (int)$row['count'];
            $backgroundColors[] = $colorMap[$status] ?? '#cbd5e1';
        }
        
        $chartData = json_encode([
            'labels' => $labels,
            'datasets' => [[
                'label' => 'Documents by Status',
                'data' => $data,
                'backgroundColor' => $backgroundColors
            ]]
        ]);

        require BASE_PATH . '/src/Views/admin/dashboard.php';
    }

    /**
     * Admin: View All Documents with filters
     */
    public function adminAllDocuments()
    {
        $db = Database::getInstance();
        $page = $_GET['page'] ?? 1;
        
        $filters = $this->buildFilterQuery(['date', 'status', 'purpose', 'submitter', 'search'], 'd.created_at');
        
        $countSql = "SELECT COUNT(*) as total FROM documents d LEFT JOIN purposes p ON d.purpose_id = p.id WHERE 1=1" . $filters['sql'];
        $countStmt = $db->query($countSql, $filters['params']);
        $totalItems = $countStmt->fetch()['total'] ?? 0;
        
        $paginator = new \App\Utils\Paginator($totalItems, 15, $page, '?page=(:num)&' . http_build_query(array_diff_key($_GET, ['page' => ''])));
        
        $sql = "SELECT d.id, d.tracking_code, d.title, d.status, d.created_at, d.guest_info, p.name as purpose_name 
                FROM documents d 
                LEFT JOIN purposes p ON d.purpose_id = p.id 
                WHERE 1=1" . $filters['sql'] . " 
                ORDER BY d.created_at DESC
                LIMIT {$paginator->getLimit()} OFFSET {$paginator->getOffset()}";
                
        $stmt = $db->query($sql, $filters['params']);
        $documents = $stmt->fetchAll();
        
        foreach ($documents as &$doc) {
            $doc['guest_name'] = 'N/A';
            if (!empty($doc['guest_info'])) {
                $guestInfo = json_decode($doc['guest_info'], true);
                if (isset($guestInfo['name'])) {
                    $doc['guest_name'] = $guestInfo['name'];
                }
            }
        }
        
        $allPurposes = $this->getAllPurposes();

        require BASE_PATH . '/src/Views/admin/all-documents.php';
    }

    /**
     * Admin: View System Overview
     */
    public function adminSystemOverview()
    {
        $db = Database::getInstance();

        // Calculate some basic system metrics
        $metrics = [
            'total_users' => $db->query("SELECT COUNT(*) as count FROM users")->fetch()['count'],
            'total_departments' => $db->query("SELECT COUNT(*) as count FROM departments")->fetch()['count'],
            'total_purposes' => $db->query("SELECT COUNT(*) as count FROM purposes")->fetch()['count'],
            'total_logs' => $db->query("SELECT COUNT(*) as count FROM document_logs")->fetch()['count'],
            'failed_jobs' => $db->query("SELECT COUNT(*) as count FROM report_jobs WHERE status = 'failed'")->fetch()['count'],
        ];

        require BASE_PATH . '/src/Views/admin/system-overview.php';
    }

    /**
     * Officer Intake Dashboard - Shows recently handled documents.
     */
    public function officerIntake()
    {
        $officerId = $_SESSION['user_id'] ?? 0;
        $db = Database::getInstance();
        $page = $_GET['page'] ?? 1;
        
        $filters = $this->buildFilterQuery(['date', 'status', 'purpose', 'submitter', 'search'], 'l.created_at');
        $params = array_merge([':officer_id' => $officerId], $filters['params']);
        
        $countSql = "SELECT COUNT(DISTINCT d.id) as total 
                     FROM documents d 
                     LEFT JOIN purposes p ON d.purpose_id = p.id
                     INNER JOIN document_logs l ON d.id = l.document_id 
                     WHERE l.user_id = :officer_id 
                       AND l.action = 'Accepted and Document Routing finalized'" . $filters['sql'];
        $countStmt = $db->query($countSql, $params);
        $totalItems = $countStmt->fetch()['total'] ?? 0;
        
        $paginator = new \App\Utils\Paginator($totalItems, 15, $page, '?page=(:num)&' . http_build_query(array_diff_key($_GET, ['page' => ''])));

        $sql = "SELECT DISTINCT d.id, d.tracking_code, d.title, d.status, d.created_at, d.guest_info, p.name as purpose_name, l.created_at as handled_at 
                FROM documents d 
                LEFT JOIN purposes p ON d.purpose_id = p.id 
                INNER JOIN document_logs l ON d.id = l.document_id 
                WHERE l.user_id = :officer_id 
                  AND l.action = 'Accepted and Document Routing finalized'" . $filters['sql'] . "
                ORDER BY l.created_at DESC
                LIMIT {$paginator->getLimit()} OFFSET {$paginator->getOffset()}";
                
        $stmt = $db->query($sql, $params);
        $documents = $stmt->fetchAll();
        $allPurposes = $this->getAllPurposes();

        // Process guest info json for the view
        foreach ($documents as &$doc) {
            $doc['guest_name'] = 'N/A';
            if (!empty($doc['guest_info'])) {
                $guestInfo = json_decode($doc['guest_info'], true);
                if (isset($guestInfo['name'])) {
                    $doc['guest_name'] = $guestInfo['name'];
                }
            }
        }

        require BASE_PATH . '/src/Views/officer/intake.php';
    }

    /**
     * Staff Tasks Dashboard - Shows documents assigned to the staff's department.
     */
    public function staffTasks()
    {
        $departmentId = $_SESSION['department_id'] ?? 0;
        $db = Database::getInstance();
        $page = $_GET['page'] ?? 1;
        
        $filters = $this->buildFilterQuery(['date', 'status', 'purpose', 'submitter', 'search'], 'd.created_at');
        $params = array_merge([':dept_id' => $departmentId], $filters['params']);
        
        $countSql = "SELECT COUNT(*) as total FROM documents d LEFT JOIN purposes p ON d.purpose_id = p.id WHERE d.current_department_id = :dept_id" . $filters['sql'];
        $countStmt = $db->query($countSql, $params);
        $totalItems = $countStmt->fetch()['total'] ?? 0;
        
        $paginator = new \App\Utils\Paginator($totalItems, 15, $page, '?page=(:num)&' . http_build_query(array_diff_key($_GET, ['page' => ''])));
        
        $sql = "SELECT d.id, d.tracking_code, d.title, d.status, d.created_at, p.name as purpose_name 
                FROM documents d 
                LEFT JOIN purposes p ON d.purpose_id = p.id 
                WHERE d.current_department_id = :dept_id" . $filters['sql'] . " 
                ORDER BY d.created_at ASC
                LIMIT {$paginator->getLimit()} OFFSET {$paginator->getOffset()}";
                
        $stmt = $db->query($sql, $params);
        $documents = $stmt->fetchAll();
        $allPurposes = $this->getAllPurposes();

        require BASE_PATH . '/src/Views/staff/tasks.php';
    }

    /**
     * Officer Completed Tasks Dashboard.
     */
    public function officerCompletedTasks()
    {
        $officerId = $_SESSION['user_id'] ?? 0;
        $db = Database::getInstance();
        $page = $_GET['page'] ?? 1;
        
        $filters = $this->buildFilterQuery(['date', 'status', 'purpose', 'submitter', 'search'], 'l.created_at');
        $params = array_merge([':officer_id' => $officerId], $filters['params']);
        
        $countSql = "SELECT COUNT(DISTINCT d.id) as total 
                     FROM documents d 
                     LEFT JOIN purposes p ON d.purpose_id = p.id
                     INNER JOIN document_logs l ON d.id = l.document_id 
                     WHERE l.user_id = :officer_id 
                       AND (l.action = 'Processing Complete' OR l.action LIKE 'Document routed to%')" . $filters['sql'];
        $countStmt = $db->query($countSql, $params);
        $totalItems = $countStmt->fetch()['total'] ?? 0;
        
        $paginator = new \App\Utils\Paginator($totalItems, 15, $page, '?page=(:num)&' . http_build_query(array_diff_key($_GET, ['page' => ''])));
        
        $sql = "SELECT DISTINCT d.id, d.tracking_code, d.title, d.status, d.created_at, d.guest_info, p.name as purpose_name, l.created_at as handled_at 
                FROM documents d 
                LEFT JOIN purposes p ON d.purpose_id = p.id 
                INNER JOIN document_logs l ON d.id = l.document_id 
                WHERE l.user_id = :officer_id 
                  AND (l.action = 'Processing Complete' OR l.action LIKE 'Document routed to%')" . $filters['sql'] . "
                ORDER BY l.created_at DESC
                LIMIT {$paginator->getLimit()} OFFSET {$paginator->getOffset()}";
                
        $stmt = $db->query($sql, $params);
        $documents = $stmt->fetchAll();
        $allPurposes = $this->getAllPurposes();

        foreach ($documents as &$doc) {
            $doc['guest_name'] = 'N/A';
            if (!empty($doc['guest_info'])) {
                $guestInfo = json_decode($doc['guest_info'], true);
                if (isset($guestInfo['name'])) {
                    $doc['guest_name'] = $guestInfo['name'];
                }
            }
        }

        require BASE_PATH . '/src/Views/officer/tasks-completed.php';
    }

    /**
     * Officer Releasing Dashboard.
     */
    public function officerReleasing()
    {
        $db = Database::getInstance();
        $page = $_GET['page'] ?? 1;
        
        $filters = $this->buildFilterQuery(['date', 'purpose', 'submitter', 'search'], 'd.created_at');
        
        $countSql = "SELECT COUNT(*) as total FROM documents d LEFT JOIN purposes p ON d.purpose_id = p.id WHERE d.status = 'ready_for_release'" . $filters['sql'];
        $countStmt = $db->query($countSql, $filters['params']);
        $totalItems = $countStmt->fetch()['total'] ?? 0;
        
        $paginator = new \App\Utils\Paginator($totalItems, 15, $page, '?page=(:num)&' . http_build_query(array_diff_key($_GET, ['page' => ''])));
        
        $sql = "SELECT d.id, d.tracking_code, d.title, d.status, d.created_at, d.guest_info, p.name as purpose_name 
                FROM documents d 
                LEFT JOIN purposes p ON d.purpose_id = p.id 
                WHERE d.status = 'ready_for_release'" . $filters['sql'] . " 
                ORDER BY d.created_at DESC
                LIMIT {$paginator->getLimit()} OFFSET {$paginator->getOffset()}";
                
        $stmt = $db->query($sql, $filters['params']);
        $documents = $stmt->fetchAll();
        $allPurposes = $this->getAllPurposes();

        foreach ($documents as &$doc) {
            $doc['guest_name'] = 'N/A';
            if (!empty($doc['guest_info'])) {
                $guestInfo = json_decode($doc['guest_info'], true);
                if (isset($guestInfo['name'])) {
                    $doc['guest_name'] = $guestInfo['name'];
                }
            }
        }

        require BASE_PATH . '/src/Views/officer/releasing.php';
    }

    /**
     * Officer Return Requests Dashboard.
     */
    public function officerReturnRequests()
    {
        require BASE_PATH . '/src/Views/officer/return-requests.php';
    }

    /**
     * Unified Statistics Dashboard for Staff and Officers.
     */
    public function statistics()
    {
        $db = Database::getInstance();
        $userRole = $_SESSION['role'] ?? '';
        
        $viewData = [];

        if ($userRole === 'officer') {
            $departmentId = $_SESSION['department_id'] ?? 0;
            $filterPurpose = $_GET['purpose'] ?? null;
            $filterSubmitter = $_GET['submitter'] ?? null;
            $searchTerm = $_GET['search'] ?? null;
            $filterDate = $_GET['date'] ?? null;
            $page = $_GET['page'] ?? 1;

            $where = ["d.status = 'completed'", "d.released_by_user_id > 0"];
            $params = [];

            // Join users to check department_id and purposes for filtering
            $join = "INNER JOIN users u ON d.released_by_user_id = u.id AND u.department_id = :dept_id
                     LEFT JOIN purposes p ON d.purpose_id = p.id";
            $params[':dept_id'] = $departmentId;

            if ($filterDate) {
                $where[] = "DATE(d.released_at) = :date";
                $params[':date'] = $filterDate;
            }
            if ($searchTerm) {
                $where[] = "(d.tracking_code LIKE :search OR json_unquote(json_extract(d.guest_info, '$.name')) LIKE :search2)";
                $params[':search'] = '%' . $searchTerm . '%';
                $params[':search2'] = '%' . $searchTerm . '%';
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

            $countSql = "SELECT COUNT(*) as total FROM documents d {$join} WHERE {$whereSql}";
            $countStmt = $db->query($countSql, $params);
            $totalItems = $countStmt->fetch()['total'] ?? 0;

            $paginator = new \App\Utils\Paginator($totalItems, 10, $page, '?page=(:num)&' . http_build_query(array_diff_key($_GET, ['page' => ''])));

            $sql = "SELECT d.*, p.name as purpose_name 
                    FROM documents d 
                    {$join} 
                    WHERE {$whereSql} 
                    ORDER BY d.released_at DESC 
                    LIMIT {$paginator->getLimit()} OFFSET {$paginator->getOffset()}";
            
            $stmt = $db->query($sql, $params);
            $documents = $stmt->fetchAll();
            
            foreach ($documents as &$doc) {
                $doc['guest_info'] = $doc['guest_info'] ? json_decode($doc['guest_info'], true) : [];
            }
            $viewData['releasedDocuments'] = $documents;
            $viewData['paginator'] = $paginator;
            $viewData['purposes'] = $this->getAllPurposes();
            $viewData['activeFilters'] = ['date', 'purpose', 'submitter', 'search'];
        }
        
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            extract($viewData);
            require BASE_PATH . '/src/Views/officer/partials/released-documents-table.php';
            exit;
        }

        extract($viewData);
        require BASE_PATH . '/src/Views/general/statistics.php';
    }

    public function getCurrentLoadData()
    {
        return $this->getChartData('received_count');
    }

    public function getThroughputData()
    {
        return $this->getChartData('processed_count + released_count');
    }

    public function getAverageProcessingTimeData()
    {
        $period = $_GET['period'] ?? 'daily';
        $departmentId = $_SESSION['department_id'] ?? 0;
        $db = Database::getInstance();

        list($startDate, $endDate, $dateFormat) = $this->getDateParameters($period);

        $sql = "SELECT 
                    DATE_FORMAT(date, '{$dateFormat}') as period_label,
                    SUM(total_processing_seconds) as total_seconds,
                    SUM(processed_count + released_count) as total_count
                FROM daily_department_metrics
                WHERE department_id = :dept_id AND date BETWEEN :start_date AND :end_date
                GROUP BY period_label";

        $stmt = $db->query($sql, [
            ':dept_id' => $departmentId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);

        $results = $stmt->fetchAll();
        $periodMap = $this->generatePeriodMap($startDate, $endDate, $period);

        foreach ($results as $row) {
            if (isset($periodMap[$row['period_label']])) {
                $totalCount = (int)$row['total_count'];
                $totalSeconds = (int)$row['total_seconds'];
                $avgHours = $totalCount > 0 ? ($totalSeconds / $totalCount) / 3600 : 0;
                $periodMap[$row['period_label']] = round($avgHours, 2);
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'labels' => array_keys($periodMap),
            'data' => array_values($periodMap),
        ]);
        exit;
    }

    private function getChartData($sumColumn)
    {
        $period = $_GET['period'] ?? 'daily';
        $departmentId = $_SESSION['department_id'] ?? 0;
        $db = Database::getInstance();

        list($startDate, $endDate, $dateFormat) = $this->getDateParameters($period);

        $sql = "SELECT 
                    DATE_FORMAT(date, '{$dateFormat}') as period_label,
                    SUM({$sumColumn}) as metric_count
                FROM daily_department_metrics
                WHERE department_id = :dept_id AND date BETWEEN :start_date AND :end_date
                GROUP BY period_label";

        $stmt = $db->query($sql, [
            ':dept_id' => $departmentId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);

        $results = $stmt->fetchAll();
        $periodMap = $this->generatePeriodMap($startDate, $endDate, $period);

        foreach ($results as $row) {
            if (isset($periodMap[$row['period_label']])) {
                $periodMap[$row['period_label']] = (int) $row['metric_count'];
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'labels' => array_keys($periodMap),
            'data' => array_values($periodMap),
        ]);
        exit;
    }

    private function getDateParameters($period)
    {
        $endDate = new \DateTime();
        $startDate = clone $endDate;

        switch ($period) {
            case 'weekly':
                $startDate->modify('-4 weeks');
                $dateFormat = '%Y-%v';
                break;
            case 'monthly':
                $startDate->modify('-12 months');
                $dateFormat = '%Y-%m';
                break;
            case 'yearly':
                $startDate->modify('-5 years');
                $dateFormat = '%Y';
                break;
            default: // daily
                $startDate->modify('-30 days');
                $dateFormat = '%Y-%m-%d';
                break;
        }

        return [$startDate->format('Y-m-d'), $endDate->format('Y-m-d'), $dateFormat];
    }

    private function getPhpDateFormat($period)
    {
        switch ($period) {
            case 'weekly': return 'Y-W';
            case 'monthly': return 'Y-m';
            case 'yearly': return 'Y';
            default: return 'Y-m-d';
        }
    }

    private function generatePeriodMap($startDateStr, $endDateStr, $period)
    {
        $periodMap = [];
        $current = new \DateTime($startDateStr);
        $endDate = new \DateTime($endDateStr);
        $format = $this->getPhpDateFormat($period);

        while ($current <= $endDate) {
            $periodMap[$current->format($format)] = 0;
            switch ($period) {
                case 'weekly': $current->modify('+1 week'); break;
                case 'monthly': $current->modify('+1 month'); break;
                case 'yearly': $current->modify('+1 year'); break;
                default: $current->modify('+1 day'); break;
            }
        }
        return $periodMap;
    }

    /**
     * Helper to build dynamic filter queries from $_GET parameters
     */
    private function buildFilterQuery(array $allowedFilters, $dateColumn = 'd.created_at')
    {
        $where = [];
        $params = [];

        if (in_array('date', $allowedFilters) && !empty($_GET['date'])) {
            $where[] = "DATE({$dateColumn}) = :filter_date";
            $params[':filter_date'] = $_GET['date'];
        }

        if (in_array('status', $allowedFilters) && !empty($_GET['status']) && $_GET['status'] !== 'all') {
            $where[] = "d.status = :filter_status";
            $params[':filter_status'] = $_GET['status'];
        }

        if (in_array('purpose', $allowedFilters) && !empty($_GET['purpose']) && $_GET['purpose'] !== 'all') {
            $where[] = "p.name = :filter_purpose";
            $params[':filter_purpose'] = $_GET['purpose'];
        }

        if (in_array('submitter', $allowedFilters) && !empty($_GET['submitter'])) {
            $where[] = "d.guest_info LIKE :filter_submitter";
            $params[':filter_submitter'] = '%"name":"%' . trim($_GET['submitter']) . '%"%';
        }

        if (in_array('search', $allowedFilters) && !empty($_GET['search'])) {
            $where[] = "(d.tracking_code LIKE :filter_search1 OR d.title LIKE :filter_search2)";
            $params[':filter_search1'] = '%' . trim($_GET['search']) . '%';
            $params[':filter_search2'] = '%' . trim($_GET['search']) . '%';
        }

        return [
            'sql' => empty($where) ? '' : ' AND ' . implode(' AND ', $where),
            'params' => $params
        ];
    }

    /**
     * Helper to fetch all purposes for the dropdown
     */
    private function getAllPurposes()
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT id, name FROM purposes ORDER BY name ASC");
        return $stmt->fetchAll();
    }
}
