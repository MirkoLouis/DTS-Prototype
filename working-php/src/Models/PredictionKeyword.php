<?php

namespace App\Models;

use App\Core\Database;

class PredictionKeyword
{
    public $id;
    public $keyword;
    public $department_id;
    public $weight;
    public $document_count;
    public $created_at;
    public $updated_at;

    public function save(): bool
    {
        $db = Database::getInstance();
        $now = date('Y-m-d H:i:s');
        $this->updated_at = $now;

        if (isset($this->id)) {
            $sql = "UPDATE prediction_keywords SET 
                keyword = :keyword, department_id = :department_id, 
                weight = :weight, document_count = :document_count, 
                updated_at = :updated_at 
                WHERE id = :id";
                
            $db->query($sql, [
                'keyword' => $this->keyword,
                'department_id' => $this->department_id,
                'weight' => $this->weight,
                'document_count' => $this->document_count,
                'updated_at' => $this->updated_at,
                'id' => $this->id
            ]);
            return true;
        } else {
            $this->created_at = $now;
            $sql = "INSERT INTO prediction_keywords 
                (keyword, department_id, weight, document_count, created_at, updated_at) 
                VALUES 
                (:keyword, :department_id, :weight, :document_count, :created_at, :updated_at)";
                
            $db->query($sql, [
                'keyword' => $this->keyword,
                'department_id' => $this->department_id,
                'weight' => $this->weight,
                'document_count' => $this->document_count,
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at
            ]);
            $this->id = $db->getConnection()->lastInsertId();
            return true;
        }
    }

    public static function findByKeyword(string $keyword): array
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM prediction_keywords WHERE keyword = :keyword", ['keyword' => $keyword]);
        $results = $stmt->fetchAll();
        
        $keywords = [];
        foreach ($results as $row) {
            $keywords[] = self::hydrate($row);
        }
        return $keywords;
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
