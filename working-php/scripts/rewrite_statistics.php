<?php
$file = __DIR__ . '/../src/Controllers/StatisticsController.php';
$content = file_get_contents($file);

// Add Cache import
if (strpos($content, 'use App\Core\Cache;') === false) {
    $content = str_replace('use App\Core\Database;', "use App\Core\Database;\nuse App\Core\Cache;", $content);
}

$search = <<<'EOD'
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
EOD;

$replace = <<<'EOD'
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
                    $whereSql .= " AND (d.released_at < :c_time OR (d.released_at = :c_time AND d.id < :c_id))";
                    $params[':c_time'] = $parts[0];
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
EOD;
$content = str_replace($search, $replace, $content);

$content = str_replace("\$page = \$_GET['page'] ?? 1;", "", $content);

file_put_contents($file, $content);
echo "StatisticsController replaced successfully.\n";
?>
