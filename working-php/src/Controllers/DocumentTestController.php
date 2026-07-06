<?php

namespace App\Controllers;

use App\Core\Database;

class DocumentTestController
{
    public function index()
    {
        // 1. Get the Database Singleton instance
        $db = Database::getInstance();

        // 2. Execute a simple query to fetch documents (similar to IntegrityMonitorController)
        // We'll join with the purposes table just to show off a real query, if purposes exist.
        // For safety, we'll just select from documents.
        $sql = "SELECT id, tracking_code, title, status, created_at FROM documents ORDER BY created_at DESC LIMIT 50";
        $stmt = $db->query($sql);
        
        // 3. Fetch all results as an associative array
        $documents = $stmt->fetchAll();

        // 4. Pass the data to the View
        require BASE_PATH . '/src/Views/test_documents.php';
    }
}
