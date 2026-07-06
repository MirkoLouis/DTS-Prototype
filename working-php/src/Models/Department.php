<?php

namespace App\Models;

use App\Core\Database;

class Department
{
    public $id;
    public $name;
    public $created_at;
    public $updated_at;

    public static function all(): array
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM departments");
        $results = $stmt->fetchAll();
        
        $departments = [];
        foreach ($results as $row) {
            $departments[] = self::hydrate($row);
        }
        return $departments;
    }

    public static function findById(int $id): ?self
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM departments WHERE id = :id", ['id' => $id]);
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
