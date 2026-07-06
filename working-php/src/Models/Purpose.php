<?php

namespace App\Models;

use App\Core\Database;

class Purpose
{
    public $id;
    public $name;
    public $is_official;
    public $requirements; // JSON string
    public $suggested_route; // JSON string
    public $created_at;
    public $updated_at;

    public static function all(): array
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM purposes");
        $results = $stmt->fetchAll();
        
        $purposes = [];
        foreach ($results as $row) {
            $purposes[] = self::hydrate($row);
        }
        return $purposes;
    }

    public static function findById(int $id): ?self
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM purposes WHERE id = :id", ['id' => $id]);
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
