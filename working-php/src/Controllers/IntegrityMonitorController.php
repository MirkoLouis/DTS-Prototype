<?php

namespace App\Controllers;

use App\Core\Database;

class IntegrityMonitorController
{
    public function index()
    {
        $db = Database::getInstance();
        $page = $_GET['page'] ?? 1;
        $perPage = 15;
        
        $searchTerm = $_GET['search'] ?? null;
        $filterStatus = $_GET['status'] ?? null;
        $filterPurpose = $_GET['purpose'] ?? null;
        $filterSubmitter = $_GET['submitter'] ?? null;
        $filterDate = $_GET['date'] ?? null;

        $where = [];
        $params = [];

        if ($searchTerm) {
            $searchTerm = trim($searchTerm);
            if (preg_match('/^DEPED-/i', $searchTerm)) {
                $where[] = "d.tracking_code LIKE :search1";
                $params[':search1'] = $searchTerm . '%';
            } else {
                $where[] = "(d.tracking_code LIKE :search1 OR d.title LIKE :search2 OR json_unquote(json_extract(d.guest_info, '$.name')) LIKE :search3 OR p.name LIKE :search4)";
                $params[':search1'] = "%{$searchTerm}%";
                $params[':search2'] = "%{$searchTerm}%";
                $params[':search3'] = "%{$searchTerm}%";
                $params[':search4'] = "%{$searchTerm}%";
            }
        }
        if ($filterStatus && $filterStatus !== 'all') {
            $where[] = "d.status = :status";
            $params[':status'] = $filterStatus;
        }
        if ($filterPurpose && $filterPurpose !== 'all') {
            $where[] = "p.name = :purpose";
            $params[':purpose'] = $filterPurpose;
        }
        if ($filterSubmitter) {
            $where[] = "LOWER(json_unquote(json_extract(d.guest_info, '$.name'))) LIKE :submitter";
            $params[':submitter'] = '%' . strtolower($filterSubmitter) . '%';
        }
        if ($filterDate) {
            $where[] = "d.created_at >= :date_start AND d.created_at <= :date_end";
            $params[':date_start'] = $filterDate . ' 00:00:00';
            $params[':date_end'] = $filterDate . ' 23:59:59';
        }

        $whereSql = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

        $countSql = "SELECT COUNT(*) as total FROM documents d LEFT JOIN purposes p ON d.purpose_id = p.id $whereSql";
        $totalItems = $db->query($countSql, $params)->fetch()['total'] ?? 0;
        
        $paginator = new \App\Utils\Paginator($totalItems, $perPage, $page, '?page=(:num)&' . http_build_query(array_diff_key($_GET, ['page' => ''])));

        $sql = "SELECT d.*, p.name as purpose_name 
                FROM documents d 
                LEFT JOIN purposes p ON d.purpose_id = p.id 
                $whereSql 
                ORDER BY d.created_at DESC 
                LIMIT {$paginator->getLimit()} OFFSET {$paginator->getOffset()}";
                
        $documents = $db->query($sql, $params)->fetchAll();

        // Process guest_info
        foreach ($documents as &$doc) {
            $doc['guest_name'] = 'N/A';
            if (!empty($doc['guest_info'])) {
                $guestInfo = json_decode($doc['guest_info'], true);
                if (isset($guestInfo['name'])) {
                    $doc['guest_name'] = $guestInfo['name'];
                }
            }
        }

        $purposes = $db->query("SELECT * FROM purposes ORDER BY name ASC")->fetchAll();
        $statuses = ['pending', 'in_transit', 'processing', 'ready_for_release', 'completed', 'frozen', 'declined'];

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            require BASE_PATH . '/src/Views/general/partials/document-list-table.php';
            exit;
        }

        require BASE_PATH . '/src/Views/admin/integrity-monitor.php';
    }
}
