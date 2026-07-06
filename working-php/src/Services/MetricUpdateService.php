<?php

namespace App\Services;

use App\Models\Document;
use App\Core\Database;

class MetricUpdateService
{
    /**
     * Update the current department of a document.
     */
    public function updateCurrentDepartment(Document $document, ?int $departmentId)
    {
        $document->current_department_id = $departmentId;
        $document->save();
    }

    /**
     * Increment the received count for a department and date.
     */
    public function incrementReceived(int $departmentId, ?string $date = null)
    {
        $db = Database::getInstance();
        $dateString = $date ? date('Y-m-d', strtotime($date)) : date('Y-m-d');
        
        $sqlUpsert = "INSERT INTO daily_department_metrics (department_id, date, received_count, created_at, updated_at) 
                      VALUES (:dept_id, :date_str, 1, NOW(), NOW()) 
                      ON DUPLICATE KEY UPDATE received_count = received_count + 1, updated_at = NOW()";
                      
        $db->query($sqlUpsert, [
            'dept_id' => $departmentId,
            'date_str' => $dateString
        ]);
    }

    /**
     * Increment the processed count and total processing seconds for a department and date.
     */
    public function incrementProcessed(int $departmentId, int $seconds, ?string $date = null)
    {
        $db = Database::getInstance();
        $dateString = $date ? date('Y-m-d', strtotime($date)) : date('Y-m-d');
        
        $sqlUpsert = "INSERT INTO daily_department_metrics (department_id, date, processed_count, total_processing_seconds, created_at, updated_at) 
                      VALUES (:dept_id, :date_str, 1, :seconds, NOW(), NOW()) 
                      ON DUPLICATE KEY UPDATE processed_count = processed_count + 1, total_processing_seconds = total_processing_seconds + :seconds2, updated_at = NOW()";
                      
        $db->query($sqlUpsert, [
            'dept_id' => $departmentId,
            'date_str' => $dateString,
            'seconds' => $seconds,
            'seconds2' => $seconds
        ]);
    }

    /**
     * Increment the released count and total processing seconds for a department and date.
     */
    public function incrementReleased(int $departmentId, int $seconds, ?string $date = null)
    {
        $db = Database::getInstance();
        $dateString = $date ? date('Y-m-d', strtotime($date)) : date('Y-m-d');
        
        $sqlUpsert = "INSERT INTO daily_department_metrics (department_id, date, released_count, total_processing_seconds, created_at, updated_at) 
                      VALUES (:dept_id, :date_str, 1, :seconds, NOW(), NOW()) 
                      ON DUPLICATE KEY UPDATE released_count = released_count + 1, total_processing_seconds = total_processing_seconds + :seconds2, updated_at = NOW()";
                      
        $db->query($sqlUpsert, [
            'dept_id' => $departmentId,
            'date_str' => $dateString,
            'seconds' => $seconds,
            'seconds2' => $seconds
        ]);
    }
}
