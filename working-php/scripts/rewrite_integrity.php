<?php
$file = __DIR__ . '/../src/Controllers/IntegrityMonitorController.php';
$content = file_get_contents($file);

if (strpos($content, 'use App\Core\Cache;') === false) {
    $content = str_replace('use App\Core\Database;', "use App\Core\Database;\nuse App\Core\Cache;", $content);
}

$search = <<<'EOD'
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
EOD;

$replace = <<<'EOD'
        $cursor = $_GET['cursor'] ?? null;
        
        $cacheKey = 'count_integrity_' . md5(json_encode($params) . $whereSql);
        $totalItems = Cache::remember($cacheKey, 300, function() use ($db, $whereSql, $params) {
            $countSql = "SELECT COUNT(*) as total FROM documents d LEFT JOIN purposes p ON d.purpose_id = p.id $whereSql";
            return $db->query($countSql, $params)->fetch()['total'] ?? 0;
        });

        if ($cursor) {
            if ($whereSql === "") {
                $whereSql = "WHERE d.id < :cursor";
            } else {
                $whereSql .= " AND d.id < :cursor";
            }
            $params[':cursor'] = $cursor;
        }

        $limit = $perPage + 1;

        $sql = "SELECT d.*, p.name as purpose_name 
                FROM documents d 
                LEFT JOIN purposes p ON d.purpose_id = p.id 
                $whereSql 
                ORDER BY d.id DESC 
                LIMIT {$limit}";
                
        $documents = $db->query($sql, $params)->fetchAll();

        $nextCursor = null;
        if (count($documents) > $perPage) {
            $nextCursor = $documents[$perPage - 1]['id'];
        }
        
        $paginator = new \App\Utils\CursorPaginator($documents, $perPage, $nextCursor, $totalItems, '?' . http_build_query(array_diff_key($_GET, ['cursor' => ''])));
        $documents = $paginator->getItems();
EOD;

$content = str_replace($search, $replace, $content);
$content = str_replace("\$page = \$_GET['page'] ?? 1;", "", $content);

file_put_contents($file, $content);
echo "IntegrityMonitorController replaced successfully.\n";
?>
