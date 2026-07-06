<?php

namespace App\Models;

use App\Core\Database;

class PublicKeyHistory
{
    public $id;
    public $user_id;
    public $public_key;
    public $activated_at;
    public $deactivated_at;
    public $created_at;
    public $updated_at;

    public function save(): bool
    {
        $db = Database::getInstance();
        $now = date('Y-m-d H:i:s');
        $this->updated_at = $now;

        if (isset($this->id)) {
            $sql = "UPDATE user_public_key_histories SET 
                user_id = :user_id, public_key = :public_key, 
                activated_at = :activated_at, deactivated_at = :deactivated_at, 
                updated_at = :updated_at 
                WHERE id = :id";
                
            $db->query($sql, [
                'user_id' => $this->user_id,
                'public_key' => $this->public_key,
                'activated_at' => $this->activated_at,
                'deactivated_at' => $this->deactivated_at,
                'updated_at' => $this->updated_at,
                'id' => $this->id
            ]);
            return true;
        } else {
            $this->created_at = $now;
            $sql = "INSERT INTO user_public_key_histories 
                (user_id, public_key, activated_at, deactivated_at, created_at, updated_at) 
                VALUES 
                (:user_id, :public_key, :activated_at, :deactivated_at, :created_at, :updated_at)";
                
            $db->query($sql, [
                'user_id' => $this->user_id,
                'public_key' => $this->public_key,
                'activated_at' => $this->activated_at,
                'deactivated_at' => $this->deactivated_at,
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at
            ]);
            $this->id = $db->getConnection()->lastInsertId();
            return true;
        }
    }

    public static function getHistoryForUser(int $userId): array
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM user_public_key_histories WHERE user_id = :user_id ORDER BY activated_at DESC", ['user_id' => $userId]);
        $results = $stmt->fetchAll();
        
        $history = [];
        foreach ($results as $row) {
            $history[] = self::hydrate($row);
        }
        return $history;
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
