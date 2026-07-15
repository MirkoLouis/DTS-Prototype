<?php
$file = __DIR__ . '/../src/Controllers/DashboardController.php';
$content = file_get_contents($file);

// Add Cache import
if (strpos($content, 'use App\Core\Cache;') === false) {
    $content = str_replace('use App\Core\Database;', "use App\Core\Database;\nuse App\Core\Cache;", $content);
}

// 1. adminAllDocuments
$search = <<<'EOD'
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
EOD;

$replace = <<<'EOD'
        $cursor = $_GET['cursor'] ?? null;
        
        $cacheKey = 'count_docs_' . md5(json_encode($filters));
        $totalItems = Cache::remember($cacheKey, 300, function() use ($db, $filters) {
            $countSql = "SELECT COUNT(*) as total FROM documents d LEFT JOIN purposes p ON d.purpose_id = p.id WHERE 1=1" . $filters['sql'];
            $countStmt = $db->query($countSql, $filters['params']);
            return $countStmt->fetch()['total'] ?? 0;
        });
        
        if ($cursor) {
            $filters['sql'] .= " AND d.id < :cursor";
            $filters['params'][':cursor'] = $cursor;
        }

        $perPage = 15;
        $limit = $perPage + 1;
        
        $sql = "SELECT d.id, d.tracking_code, d.title, d.status, d.created_at, d.guest_info, p.name as purpose_name 
                FROM documents d 
                LEFT JOIN purposes p ON d.purpose_id = p.id 
                WHERE 1=1" . $filters['sql'] . " 
                ORDER BY d.id DESC
                LIMIT {$limit}";
                
        $stmt = $db->query($sql, $filters['params']);
        $documents = $stmt->fetchAll();
        
        $nextCursor = null;
        if (count($documents) > $perPage) {
            $nextCursor = $documents[$perPage - 1]['id'];
        }
        
        $paginator = new \App\Utils\CursorPaginator($documents, $perPage, $nextCursor, $totalItems, '?' . http_build_query(array_diff_key($_GET, ['cursor' => ''])));
        $documents = $paginator->getItems();
EOD;
$content = str_replace($search, $replace, $content);


// 2. officerIntake
$search = <<<'EOD'
        $countSql = "SELECT COUNT(d.id) as total 
                     FROM documents d 
                     LEFT JOIN purposes p ON d.purpose_id = p.id
                     INNER JOIN (
                         SELECT document_id, MAX(created_at) as handled_at
                         FROM document_logs
                         WHERE user_id = :officer_id 
                           AND action = 'Accepted and Document Routing finalized'
                         GROUP BY document_id
                     ) max_logs ON d.id = max_logs.document_id 
                     WHERE 1=1 " . $filters['sql'];
        $countStmt = $db->query($countSql, $params);
        $totalItems = $countStmt->fetch()['total'] ?? 0;
        
        $paginator = new \App\Utils\Paginator($totalItems, 15, $page, '?page=(:num)&' . http_build_query(array_diff_key($_GET, ['page' => ''])));

        $sql = "SELECT d.id, d.tracking_code, d.title, d.status, d.created_at, d.guest_info, p.name as purpose_name, max_logs.handled_at 
                FROM documents d 
                LEFT JOIN purposes p ON d.purpose_id = p.id 
                INNER JOIN (
                    SELECT document_id, MAX(created_at) as handled_at
                    FROM document_logs
                    WHERE user_id = :officer_id 
                      AND action = 'Accepted and Document Routing finalized'
                    GROUP BY document_id
                ) max_logs ON d.id = max_logs.document_id 
                WHERE 1=1 " . $filters['sql'] . "
                ORDER BY max_logs.handled_at DESC
                LIMIT {$paginator->getLimit()} OFFSET {$paginator->getOffset()}";
                
        $stmt = $db->query($sql, $params);
        $documents = $stmt->fetchAll();
EOD;

$replace = <<<'EOD'
        $cursor = $_GET['cursor'] ?? null;
        
        $cacheKey = 'count_intake_' . md5(json_encode(array_merge($params, $filters)));
        $totalItems = Cache::remember($cacheKey, 300, function() use ($db, $params, $filters) {
            $countSql = "SELECT COUNT(d.id) as total 
                         FROM documents d 
                         LEFT JOIN purposes p ON d.purpose_id = p.id
                         INNER JOIN (
                             SELECT document_id, MAX(created_at) as handled_at
                             FROM document_logs
                             WHERE user_id = :officer_id 
                               AND action = 'Accepted and Document Routing finalized'
                             GROUP BY document_id
                         ) max_logs ON d.id = max_logs.document_id 
                         WHERE 1=1 " . $filters['sql'];
            $countStmt = $db->query($countSql, $params);
            return $countStmt->fetch()['total'] ?? 0;
        });
        
        if ($cursor) {
            $parts = explode('_', $cursor);
            if (count($parts) == 2) {
                $filters['sql'] .= " AND (max_logs.handled_at < :c_time OR (max_logs.handled_at = :c_time AND d.id < :c_id))";
                $params[':c_time'] = $parts[0];
                $params[':c_id'] = $parts[1];
            }
        }

        $perPage = 15;
        $limit = $perPage + 1;

        $sql = "SELECT d.id, d.tracking_code, d.title, d.status, d.created_at, d.guest_info, p.name as purpose_name, max_logs.handled_at 
                FROM documents d 
                LEFT JOIN purposes p ON d.purpose_id = p.id 
                INNER JOIN (
                    SELECT document_id, MAX(created_at) as handled_at
                    FROM document_logs
                    WHERE user_id = :officer_id 
                      AND action = 'Accepted and Document Routing finalized'
                    GROUP BY document_id
                ) max_logs ON d.id = max_logs.document_id 
                WHERE 1=1 " . $filters['sql'] . "
                ORDER BY max_logs.handled_at DESC, d.id DESC
                LIMIT {$limit}";
                
        $stmt = $db->query($sql, $params);
        $documents = $stmt->fetchAll();
        
        $nextCursor = null;
        if (count($documents) > $perPage) {
            $nextCursor = $documents[$perPage - 1]['handled_at'] . '_' . $documents[$perPage - 1]['id'];
        }
        
        $paginator = new \App\Utils\CursorPaginator($documents, $perPage, $nextCursor, $totalItems, '?' . http_build_query(array_diff_key($_GET, ['cursor' => ''])));
        $documents = $paginator->getItems();
EOD;
$content = str_replace($search, $replace, $content);


// 3. staffTasks
$search = <<<'EOD'
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
EOD;

$replace = <<<'EOD'
        $cursor = $_GET['cursor'] ?? null;
        
        $cacheKey = 'count_staff_' . md5(json_encode(array_merge($params, $filters)));
        $totalItems = Cache::remember($cacheKey, 300, function() use ($db, $params, $filters) {
            $countSql = "SELECT COUNT(*) as total FROM documents d LEFT JOIN purposes p ON d.purpose_id = p.id WHERE d.current_department_id = :dept_id" . $filters['sql'];
            $countStmt = $db->query($countSql, $params);
            return $countStmt->fetch()['total'] ?? 0;
        });
        
        if ($cursor) {
            $filters['sql'] .= " AND d.id > :cursor";
            $params[':cursor'] = $cursor;
        }

        $perPage = 15;
        $limit = $perPage + 1;
        
        $sql = "SELECT d.id, d.tracking_code, d.title, d.status, d.created_at, p.name as purpose_name 
                FROM documents d 
                LEFT JOIN purposes p ON d.purpose_id = p.id 
                WHERE d.current_department_id = :dept_id" . $filters['sql'] . " 
                ORDER BY d.id ASC
                LIMIT {$limit}";
                
        $stmt = $db->query($sql, $params);
        $documents = $stmt->fetchAll();
        
        $nextCursor = null;
        if (count($documents) > $perPage) {
            $nextCursor = $documents[$perPage - 1]['id'];
        }
        
        $paginator = new \App\Utils\CursorPaginator($documents, $perPage, $nextCursor, $totalItems, '?' . http_build_query(array_diff_key($_GET, ['cursor' => ''])));
        $documents = $paginator->getItems();
EOD;
$content = str_replace($search, $replace, $content);

// 4. officerCompletedTasks
$search = <<<'EOD'
        $countSql = "SELECT COUNT(d.id) as total 
                     FROM documents d 
                     LEFT JOIN purposes p ON d.purpose_id = p.id
                     INNER JOIN (
                         SELECT document_id, MAX(created_at) as handled_at
                         FROM document_logs
                         WHERE user_id = :officer_id 
                           AND (action = 'Processing Complete' OR action LIKE 'Document routed to%')
                         GROUP BY document_id
                     ) max_logs ON d.id = max_logs.document_id 
                     WHERE 1=1 " . $filters['sql'];
        $countStmt = $db->query($countSql, $params);
        $totalItems = $countStmt->fetch()['total'] ?? 0;
        
        $paginator = new \App\Utils\Paginator($totalItems, 15, $page, '?page=(:num)&' . http_build_query(array_diff_key($_GET, ['page' => ''])));
        
        $sql = "SELECT d.id, d.tracking_code, d.title, d.status, d.created_at, d.guest_info, p.name as purpose_name, max_logs.handled_at 
                FROM documents d 
                LEFT JOIN purposes p ON d.purpose_id = p.id 
                INNER JOIN (
                    SELECT document_id, MAX(created_at) as handled_at
                    FROM document_logs
                    WHERE user_id = :officer_id 
                      AND (action = 'Processing Complete' OR action LIKE 'Document routed to%')
                    GROUP BY document_id
                ) max_logs ON d.id = max_logs.document_id 
                WHERE 1=1 " . $filters['sql'] . "
                ORDER BY max_logs.handled_at DESC
                LIMIT {$paginator->getLimit()} OFFSET {$paginator->getOffset()}";
                
        $stmt = $db->query($sql, $params);
        $documents = $stmt->fetchAll();
EOD;

$replace = <<<'EOD'
        $cursor = $_GET['cursor'] ?? null;
        
        $cacheKey = 'count_completed_' . md5(json_encode(array_merge($params, $filters)));
        $totalItems = Cache::remember($cacheKey, 300, function() use ($db, $params, $filters) {
            $countSql = "SELECT COUNT(d.id) as total 
                         FROM documents d 
                         LEFT JOIN purposes p ON d.purpose_id = p.id
                         INNER JOIN (
                             SELECT document_id, MAX(created_at) as handled_at
                             FROM document_logs
                             WHERE user_id = :officer_id 
                               AND (action = 'Processing Complete' OR action LIKE 'Document routed to%')
                             GROUP BY document_id
                         ) max_logs ON d.id = max_logs.document_id 
                         WHERE 1=1 " . $filters['sql'];
            $countStmt = $db->query($countSql, $params);
            return $countStmt->fetch()['total'] ?? 0;
        });
        
        if ($cursor) {
            $parts = explode('_', $cursor);
            if (count($parts) == 2) {
                $filters['sql'] .= " AND (max_logs.handled_at < :c_time OR (max_logs.handled_at = :c_time AND d.id < :c_id))";
                $params[':c_time'] = $parts[0];
                $params[':c_id'] = $parts[1];
            }
        }

        $perPage = 15;
        $limit = $perPage + 1;
        
        $sql = "SELECT d.id, d.tracking_code, d.title, d.status, d.created_at, d.guest_info, p.name as purpose_name, max_logs.handled_at 
                FROM documents d 
                LEFT JOIN purposes p ON d.purpose_id = p.id 
                INNER JOIN (
                    SELECT document_id, MAX(created_at) as handled_at
                    FROM document_logs
                    WHERE user_id = :officer_id 
                      AND (action = 'Processing Complete' OR action LIKE 'Document routed to%')
                    GROUP BY document_id
                ) max_logs ON d.id = max_logs.document_id 
                WHERE 1=1 " . $filters['sql'] . "
                ORDER BY max_logs.handled_at DESC, d.id DESC
                LIMIT {$limit}";
                
        $stmt = $db->query($sql, $params);
        $documents = $stmt->fetchAll();
        
        $nextCursor = null;
        if (count($documents) > $perPage) {
            $nextCursor = $documents[$perPage - 1]['handled_at'] . '_' . $documents[$perPage - 1]['id'];
        }
        
        $paginator = new \App\Utils\CursorPaginator($documents, $perPage, $nextCursor, $totalItems, '?' . http_build_query(array_diff_key($_GET, ['cursor' => ''])));
        $documents = $paginator->getItems();
EOD;
$content = str_replace($search, $replace, $content);

// 5. officerReleasing
$search = <<<'EOD'
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
EOD;

$replace = <<<'EOD'
        $cursor = $_GET['cursor'] ?? null;
        
        $cacheKey = 'count_releasing_' . md5(json_encode($filters));
        $totalItems = Cache::remember($cacheKey, 300, function() use ($db, $filters) {
            $countSql = "SELECT COUNT(*) as total FROM documents d LEFT JOIN purposes p ON d.purpose_id = p.id WHERE d.status = 'ready_for_release'" . $filters['sql'];
            $countStmt = $db->query($countSql, $filters['params']);
            return $countStmt->fetch()['total'] ?? 0;
        });
        
        if ($cursor) {
            $filters['sql'] .= " AND d.id < :cursor";
            $filters['params'][':cursor'] = $cursor;
        }

        $perPage = 15;
        $limit = $perPage + 1;
        
        $sql = "SELECT d.id, d.tracking_code, d.title, d.status, d.created_at, d.guest_info, p.name as purpose_name 
                FROM documents d 
                LEFT JOIN purposes p ON d.purpose_id = p.id 
                WHERE d.status = 'ready_for_release'" . $filters['sql'] . " 
                ORDER BY d.id DESC
                LIMIT {$limit}";
                
        $stmt = $db->query($sql, $filters['params']);
        $documents = $stmt->fetchAll();
        
        $nextCursor = null;
        if (count($documents) > $perPage) {
            $nextCursor = $documents[$perPage - 1]['id'];
        }
        
        $paginator = new \App\Utils\CursorPaginator($documents, $perPage, $nextCursor, $totalItems, '?' . http_build_query(array_diff_key($_GET, ['cursor' => ''])));
        $documents = $paginator->getItems();
EOD;
$content = str_replace($search, $replace, $content);

// 6. statistics
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
            
            $cacheKey = 'count_stats_' . md5(json_encode($params) . $whereSql);
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

// Remove unused $page = $_GET['page'] ?? 1; declarations
$content = str_replace("\$page = \$_GET['page'] ?? 1;", "", $content);

file_put_contents($file, $content);
echo "DashboardController replaced successfully.\n";
?>
