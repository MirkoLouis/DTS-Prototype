<?php

namespace App\Controllers;

use App\Core\Database;

class IntakeController
{
    public function index()
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

    public function find()
    {
        $trackingCode = $_POST['tracking_code'] ?? '';
        if ($trackingCode) {
            $db = Database::getInstance();
            $doc = $db->query("SELECT id FROM documents WHERE tracking_code = :code", ['code' => $trackingCode])->fetch();
            if ($doc) {
                header('Location: /documents/' . $doc['id'] . '/manage');
                exit;
            }
        }
        
        header('Location: /intake?error=Document+not+found');
        exit;
    }

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

    private function getAllPurposes()
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT id, name FROM purposes ORDER BY name ASC");
        return $stmt->fetchAll();
    }
}
