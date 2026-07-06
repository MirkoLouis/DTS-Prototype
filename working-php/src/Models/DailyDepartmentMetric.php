<?php

namespace App\Models;

use App\Core\Database;

class DailyDepartmentMetric
{
    public $id;
    public $department_id;
    public $date;
    public $received_count;
    public $processed_count;
    public $released_count;
    public $total_processing_seconds;
    public $created_at;
    public $updated_at;

    public function save(): bool
    {
        $db = Database::getInstance();
        $now = date('Y-m-d H:i:s');
        $this->updated_at = $now;

        if (isset($this->id)) {
            $sql = "UPDATE daily_department_metrics SET 
                department_id = :department_id, date = :date, 
                received_count = :received_count, processed_count = :processed_count, 
                released_count = :released_count, total_processing_seconds = :total_processing_seconds, 
                updated_at = :updated_at 
                WHERE id = :id";
                
            $db->query($sql, [
                'department_id' => $this->department_id,
                'date' => $this->date,
                'received_count' => $this->received_count,
                'processed_count' => $this->processed_count,
                'released_count' => $this->released_count,
                'total_processing_seconds' => $this->total_processing_seconds,
                'updated_at' => $this->updated_at,
                'id' => $this->id
            ]);
            return true;
        } else {
            $this->created_at = $now;
            $sql = "INSERT INTO daily_department_metrics 
                (department_id, date, received_count, processed_count, released_count, total_processing_seconds, created_at, updated_at) 
                VALUES 
                (:department_id, :date, :received_count, :processed_count, :released_count, :total_processing_seconds, :created_at, :updated_at)";
                
            $db->query($sql, [
                'department_id' => $this->department_id,
                'date' => $this->date,
                'received_count' => $this->received_count,
                'processed_count' => $this->processed_count,
                'released_count' => $this->released_count,
                'total_processing_seconds' => $this->total_processing_seconds,
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at
            ]);
            $this->id = $db->getConnection()->lastInsertId();
            return true;
        }
    }

    public static function findByDepartmentAndDate(int $departmentId, string $date): ?self
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM daily_department_metrics WHERE department_id = :dept AND date = :date", [
            'dept' => $departmentId,
            'date' => $date
        ]);
        $data = $stmt->fetch();
        return $data ? self::hydrate($data) : null;
    }

    private static function hydrate(array $data): self
    {
        $model = new self();
        foreach ($data as $key => $value) {
            if (property_exists($model, $key)) {
                $model->$key = $value;
            }
        }
        return $model;
    }
}
