<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\IntegrityManager;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class GuestController
{
    public function welcome()
    {
        $db = Database::getInstance();
        
        $purposes = $db->query("SELECT * FROM purposes WHERE is_official = 1 ORDER BY name")->fetchAll();
        $stmt = $db->query("SELECT id, name FROM departments ORDER BY name ASC");
        $departments = $stmt->fetchAll();

        // Pass this directly to the view instead of extracting
        $viewData = [
            'purposes' => $purposes,
            'departments' => $departments
        ]; // Pass structured data
        foreach ($purposes as &$purpose) {
            $purpose['requirements'] = $purpose['requirements'] ? json_decode($purpose['requirements'], true) : [];
        }

        ob_start();
        require __DIR__ . '/../Views/guest/welcome.php';
        $content = ob_get_clean();
        require __DIR__ . '/../Views/layouts/guest.php';
    }

    public function store()
    {
        $db = Database::getInstance();

        $guest_name = $_POST['guest_name'] ?? '';
        $guest_email = $_POST['guest_email'] ?? '';
        $guest_phone = $_POST['guest_phone'] ?? '';
        $district = $_POST['district'] ?? '';
        $department = $_POST['department'] ?? '';
        $title = $_POST['title'] ?? '';
        $purpose_id = $_POST['purpose_id'] ?? '';
        $other_purpose_text = $_POST['other_purpose_text'] ?? '';

        if (empty($guest_name) || empty($district) || empty($department) || empty($title) || $purpose_id === '') {
            $_SESSION['error'] = "Please fill in all required fields.";
            header("Location: /");
            exit;
        }

        try {
            $workflow = new \App\Services\DocumentWorkflowService();
            $result = $workflow->submitDocument($_POST);
            
            $trackingCode = $result['tracking_code'];
            $documentId = $result['document_id'];
        } catch (\Exception $e) {
            $_SESSION['error'] = "An error occurred during submission: " . $e->getMessage();
            header("Location: /");
            exit;
        }

        header("Location: /success?tracking_code={$trackingCode}&document_id={$documentId}");
        exit;
    }

    public function success()
    {
        $tracking_code = trim($_GET['tracking_code'] ?? '');
        if (!$tracking_code) {
            header('Location: /');
            exit;
        }

        $options = new QROptions([
            'version'    => 5,
            'outputType' => \chillerlan\QRCode\Output\QRGdImagePNG::class,
            'eccLevel'   => \chillerlan\QRCode\Common\EccLevel::L,
            'scale'      => 5,
        ]);
        
        $qrcode = new QRCode($options);
        $qrCodeImage = (string) $qrcode->render($tracking_code);

        ob_start();
        require __DIR__ . '/../Views/guest/success.php';
        $content = ob_get_clean();
        require __DIR__ . '/../Views/layouts/guest.php';
    }

    public function track()
    {
        $codesParam = $_GET['codes'] ?? '';
        $trackingCodes = array_filter(array_map('trim', explode(',', $codesParam)));

        if (empty($trackingCodes)) {
            $_SESSION['info'] = "Please enter a tracking code to view its status.";
            header("Location: /");
            exit;
        }

        $db = Database::getInstance();
        $placeholders = implode(',', array_fill(0, count($trackingCodes), '?'));
        
        $sql = "SELECT d.*, p.name as purpose_name, p.suggested_route 
                FROM documents d 
                LEFT JOIN purposes p ON d.purpose_id = p.id 
                WHERE d.tracking_code IN ($placeholders)";
        $stmt = $db->getConnection()->prepare($sql);
        $stmt->execute(array_values($trackingCodes));
        $documents = $stmt->fetchAll();

        foreach ($documents as &$doc) {
            $stmt = $db->query("SELECT l.*, u.name as user_name FROM document_logs l LEFT JOIN users u ON l.user_id = u.id WHERE l.document_id = :doc_id ORDER BY l.created_at DESC", [':doc_id' => $doc['id']]);
            $doc['logs'] = $stmt->fetchAll();
            $doc['suggested_route'] = $doc['suggested_route'] ? json_decode($doc['suggested_route'], true) : [];
        }

        ob_start();
        require __DIR__ . '/../Views/guest/track.php';
        $content = ob_get_clean();
        require __DIR__ . '/../Views/layouts/guest.php';
    }

    public function getTrackedDocumentModule($tracking_code)
    {
        $tracking_code = trim($tracking_code);
        $db = Database::getInstance();
        
        $stmt = $db->query("SELECT d.*, p.name as purpose_name, p.suggested_route FROM documents d LEFT JOIN purposes p ON d.purpose_id = p.id WHERE d.tracking_code = :tracking_code", [':tracking_code' => $tracking_code]);
        $document = $stmt->fetch();

        if (!$document) {
            http_response_code(404);
            echo "Not Found";
            return;
        }

        $stmt = $db->query("SELECT l.*, u.name as user_name FROM document_logs l LEFT JOIN users u ON l.user_id = u.id WHERE l.document_id = :doc_id ORDER BY l.created_at DESC", [':doc_id' => $document['id']]);
        $document['logs'] = $stmt->fetchAll();
        $document['suggested_route'] = $document['suggested_route'] ? json_decode($document['suggested_route'], true) : [];

        ob_start();
        require __DIR__ . '/../Views/guest/partials/document-card.php';
        echo ob_get_clean();
    }

    public function getStatusUpdates()
    {
        $codesParam = $_GET['codes'] ?? '';
        $trackingCodes = array_filter(array_map('trim', explode(',', $codesParam)));

        if (empty($trackingCodes)) {
            header('Content-Type: application/json');
            echo json_encode([]);
            return;
        }

        $db = Database::getInstance();
        $placeholders = implode(',', array_fill(0, count($trackingCodes), '?'));
        
        $sql = "SELECT tracking_code, status, current_step FROM documents WHERE tracking_code IN ($placeholders)";
        $stmt = $db->getConnection()->prepare($sql);
        $stmt->execute(array_values($trackingCodes));
        $statuses = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode($statuses);
    }
}
