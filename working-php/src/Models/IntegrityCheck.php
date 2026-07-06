<?php

namespace App\Models;

use App\Core\Database;

class IntegrityCheck
{
    public $id; // uuid
    public $user_id;
    public $status;
    public $progress;
    public $results; // JSON
    public $error_message;
    public $created_at;
    public $updated_at;

    public function save(): bool
    {
        $db = Database::getInstance();
        $now = date('Y-m-d H:i:s');
        $this->updated_at = $now;

        $exists = $db->query("SELECT id FROM integrity_checks WHERE id = :id", ['id' => $this->id])->fetch();

        if ($exists) {
            $sql = "UPDATE integrity_checks SET 
                user_id = :user_id, status = :status, progress = :progress, 
                results = :results, error_message = :error_message, updated_at = :updated_at 
                WHERE id = :id";
                
            $db->query($sql, [
                'user_id' => $this->user_id,
                'status' => $this->status,
                'progress' => $this->progress,
                'results' => $this->results,
                'error_message' => $this->error_message,
                'updated_at' => $this->updated_at,
                'id' => $this->id
            ]);
            return true;
        } else {
            $this->created_at = $now;
            $sql = "INSERT INTO integrity_checks 
                (id, user_id, status, progress, results, error_message, created_at, updated_at) 
                VALUES 
                (:id, :user_id, :status, :progress, :results, :error_message, :created_at, :updated_at)";
                
            $db->query($sql, [
                'id' => $this->id,
                'user_id' => $this->user_id,
                'status' => $this->status,
                'progress' => $this->progress,
                'results' => $this->results,
                'error_message' => $this->error_message,
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at
            ]);
            return true;
        }
    }

    public static function findById(string $id): ?self
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM integrity_checks WHERE id = :id", ['id' => $id]);
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
