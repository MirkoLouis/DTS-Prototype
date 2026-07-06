<?php

namespace App\Models;

use App\Core\Database;

class DocumentLog
{
    public $id;
    public $document_id;
    public $user_id;
    public $action;
    public $remarks;
    public $hash;
    public $previous_hash;
    public $signature;
    public $document_state_hash;
    public $created_at;
    public $updated_at;

    public static function getByDocumentId(int $documentId): array
    {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM document_logs WHERE document_id = :document_id ORDER BY id ASC", ['document_id' => $documentId]);
        $results = $stmt->fetchAll();
        
        $logs = [];
        foreach ($results as $row) {
            $logs[] = self::hydrate($row);
        }
        return $logs;
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
