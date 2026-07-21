<?php
namespace App\Services;

use App\Core\Database;

class DocumentQueryService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findByTrackingCode(string $trackingCode, string $logOrder = 'DESC'): ?array
    {
        $stmt = $this->db->query("SELECT d.*, p.name as purpose_name, p.suggested_route 
                                  FROM documents d 
                                  LEFT JOIN purposes p ON d.purpose_id = p.id 
                                  WHERE d.tracking_code = :tracking_code", [':tracking_code' => $trackingCode]);
        $document = $stmt->fetch();
        
        if ($document) {
            $document['logs'] = $this->getLogsForDocumentOrdered($document['id'], $logOrder);
            $document['suggested_route'] = $document['suggested_route'] ? json_decode($document['suggested_route'], true) : [];
        }
        
        return $document ?: null;
    }

    public function getLogsForDocumentOrdered(int $documentId, string $order = 'DESC'): array
    {
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $stmt = $this->db->query("SELECT l.*, u.name as user_name 
                                  FROM document_logs l 
                                  LEFT JOIN users u ON l.user_id = u.id 
                                  WHERE l.document_id = :doc_id 
                                  ORDER BY l.created_at $order", [':doc_id' => $documentId]);
        return $stmt->fetchAll();
    }

    public function getMultipleWithLogs(array $trackingCodes): array
    {
        if (empty($trackingCodes)) return [];

        $placeholders = implode(',', array_fill(0, count($trackingCodes), '?'));
        
        $sql = "SELECT d.*, p.name as purpose_name, p.suggested_route 
                FROM documents d 
                LEFT JOIN purposes p ON d.purpose_id = p.id 
                WHERE d.tracking_code IN ($placeholders)";
        
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute(array_values($trackingCodes));
        $documents = $stmt->fetchAll();
        
        if (empty($documents)) return [];

        // Optimize N+1 by fetching all logs at once
        $docIds = array_column($documents, 'id');
        $idPlaceholders = implode(',', array_fill(0, count($docIds), '?'));
        
        $logSql = "SELECT l.*, u.name as user_name 
                   FROM document_logs l 
                   LEFT JOIN users u ON l.user_id = u.id 
                   WHERE l.document_id IN ($idPlaceholders) 
                   ORDER BY l.created_at DESC";
        $logStmt = $this->db->getConnection()->prepare($logSql);
        $logStmt->execute(array_values($docIds));
        $allLogs = $logStmt->fetchAll();

        // Group logs by document_id
        $logsByDoc = [];
        foreach ($allLogs as $log) {
            $logsByDoc[$log['document_id']][] = $log;
        }

        foreach ($documents as &$doc) {
            $doc['logs'] = $logsByDoc[$doc['id']] ?? [];
            $doc['suggested_route'] = $doc['suggested_route'] ? json_decode($doc['suggested_route'], true) : [];
        }

        return $documents;
    }
}
