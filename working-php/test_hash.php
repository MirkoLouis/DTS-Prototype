<?php
define('BASE_PATH', __DIR__);
require 'vendor/autoload.php';
$db = \App\Core\Database::getInstance();

$trackingCode = 'DEPED-044BBD417D';
$stmt = $db->query("SELECT * FROM documents WHERE tracking_code = :tc", [':tc' => $trackingCode]);
$doc = $stmt->fetch();
if (!$doc) {
    echo "Document not found!\n";
    exit;
}

$latestLog = $db->query("SELECT * FROM document_logs WHERE document_id = :id ORDER BY id DESC LIMIT 1", [':id' => $doc['id']])->fetch();

$currentStateHash = \App\Core\IntegrityManager::calculateStateHash($doc);
echo "Current Hash: $currentStateHash\n";
echo "Latest Log Hash: {$latestLog['document_state_hash']}\n";
echo "Latest Log Action: {$latestLog['action']}\n";

