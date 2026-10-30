<?php

namespace App\Modules\Documents;

use App\Common\Database\Database;
use Dompdf\Dompdf;
use Dompdf\Options;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class DocumentController
{
    /**
     * Display the specified document's data and logs.
     *
     * @param string $tracking_code
     */
    public function show($tracking_code)
    {
        $service = new \App\Modules\Documents\DocumentQueryService();
        $document = $service->findByTrackingCode($tracking_code, 'ASC');

        if (!$document) {
            header("HTTP/1.0 404 Not Found");
            echo "Document not found.";
            exit;
        }

        // Enforce document visibility: staff may only open documents routed through their department
        $this->requireViewAccess($document);

        if (!empty($_SERVER['HTTP_REFERER'])) {
            $refererPath = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH) ?? '';
            if (!empty($refererPath) && !str_contains($refererPath, '/hash-chain') && !str_contains($refererPath, '/documents/')) {
                $_SESSION['doc_return_url'] = $_SERVER['HTTP_REFERER'];
            }
        }

        $logs = $document['logs'];

        require BASE_PATH . '/src/Views/general/show-document.php';
    }

    /**
     * Display the hash chain for a specific document.
     *
     * @param string $tracking_code
     */
    public function showHashChain($tracking_code)
    {
        $service = new \App\Modules\Documents\DocumentQueryService();
        $document = $service->findByTrackingCode($tracking_code, 'ASC');

        if (!$document) {
            header("HTTP/1.0 404 Not Found");
            echo "Document not found.";
            exit;
        }

        // Enforce document visibility: staff may only open documents routed through their department
        $this->requireViewAccess($document);

        $logs = $document['logs'];

        require BASE_PATH . '/src/Views/general/document-hash-chain.php';
    }

    /**
     * Redirect the current user to the dashboard unless DocumentPolicy::view grants access.
     */
    private function requireViewAccess(array $document): void
    {
        // Resolve the logged-in user and ask the policy
        $user = \App\Modules\Users\UserModel::findById($_SESSION['user_id'] ?? 0);
        if ($user && (new \App\Modules\Documents\DocumentPolicy())->view($user, $document)) {
            return;
        }

        // Deny with a flash message and no document data in the response
        $_SESSION['error'] = 'You do not have access to this document.';
        header('Location: /dashboard');
        exit;
    }

    /**
     * Find a document by its tracking code and redirect to the manage page.
     */
    public function find()
    {
        $trackingCode = trim($_POST['tracking_code'] ?? '');

        if (empty($trackingCode)) {
            $_SESSION['error'] = "Tracking code is required.";
            header("Location: /intake");
            exit;
        }

        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM documents WHERE tracking_code = :tracking_code", [':tracking_code' => $trackingCode]);
        $document = $stmt->fetch();

        if (!$document) {
            $_SESSION['error'] = "Document " . htmlspecialchars($trackingCode) . " not found.";
            header("Location: /intake");
            exit;
        }

        switch ($document['status']) {
            case 'pending':
                header("Location: /documents/{$document['id']}/manage");
                exit;

            case 'processing':
            case 'in_transit':
                $_SESSION['info'] = "This document is already in process and cannot be intaked again.";
                header("Location: /intake");
                exit;

            case 'ready_for_release':
            case 'completed':
            case 'declined':
                $_SESSION['info'] = "This document has already been released, please check your tracking code again.";
                header("Location: /intake");
                exit;

            default:
                $_SESSION['error'] = "This document cannot be processed at this time. Its current status is: " . ucfirst($document['status']);
                header("Location: /intake");
                exit;
        }
    }

    /**
     * Show the form for managing a document's route (Intake).
     *
     * @param string $id
     */
    public function manage($id)
    {
        $db = Database::getInstance();
        
        $stmt = $db->query("SELECT d.*, p.name as purpose_name, p.is_official, p.suggested_route 
                            FROM documents d 
                            LEFT JOIN purposes p ON d.purpose_id = p.id 
                            WHERE d.id = :id", [':id' => $id]);
        $document = $stmt->fetch();

        if (!$document) {
            header("Location: /intake");
            exit;
        }

        // Official purposes get the stored route with the guest's unit injected; "Others" gets none
        $suggestedRoute = (new \App\Modules\Routing\HeuristicRoutingService())->suggest(
            ['is_official' => $document['is_official'] ?? 0, 'suggested_route' => $document['suggested_route'] ?? null],
            $document['department'] ?? null
        );

        $document['suggested_route'] = $suggestedRoute;

        // Fetch departments for the route building dropdown
        $stmt = $db->query("SELECT id, name FROM departments ORDER BY name ASC");
        $departments = $stmt->fetchAll();

        require BASE_PATH . '/src/Views/officer/manage-documents.php';
    }

    public function finalize($id)
    {
        $finalRouteJson = $_POST['final_route'] ?? '';
        $submittedPin = $_POST['pin'] ?? '';
        $pin = \App\Modules\Users\SecurityPinCacheService::resolvePin($submittedPin);

        if (empty($finalRouteJson) || empty($pin)) {
            $_SESSION['error'] = "Route and Security PIN are required.";
            header("Location: /documents/{$id}/manage");
            exit;
        }

        $routeNames = json_decode($finalRouteJson, true);

        // A route that is not a JSON list would fail the typed service call, so reject it here
        if (!empty($routeNames) && !is_array($routeNames)) {
            $_SESSION['error'] = "Invalid route.";
            header("Location: /documents/{$id}/manage");
            exit;
        }

        if (empty($routeNames)) {
            $_SESSION['error'] = "The route cannot be empty. Please add at least one step.";
            header("Location: /documents/{$id}/manage");
            exit;
        }

        try {
            $workflow = new \App\Modules\Documents\DocumentWorkflowService();
            $officer = \App\Modules\Users\UserModel::findById($_SESSION['user_id']);
            $workflow->finalizeIntake((int)$id, $routeNames, $officer, $pin);
            
            $doc = \App\Modules\Documents\DocumentModel::findById((int)$id);
            $trackingCode = $doc ? $doc->tracking_code : '';
            $_SESSION['success'] = "Document {$trackingCode} accepted and is now in transit!";
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Action Denied')) {
                $_SESSION['console_error'] = $e->getMessage();
            } else {
                $_SESSION['error'] = $e->getMessage();
            }
            header("Location: /documents/{$id}/manage");
            exit;
        }

        header("Location: /intake");
        exit;
    }

    public function scan()
    {
        $trackingCode = trim($_POST['tracking_code'] ?? '');
        $submittedPin = $_POST['pin'] ?? '';
        $pin = \App\Modules\Users\SecurityPinCacheService::resolvePin($submittedPin);

        if (empty($trackingCode)) {
            $_SESSION['error'] = "Tracking code is required.";
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/'));
            exit;
        }

        if (empty($pin)) {
            $_SESSION['error'] = "Security PIN is required.";
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/'));
            exit;
        }

        try {
            $workflow = new \App\Modules\Documents\DocumentWorkflowService();
            $user = \App\Modules\Users\UserModel::findById($_SESSION['user_id']);
            $redirectRouteKey = $workflow->scanDocument($trackingCode, $user, $pin);
            
            if ($redirectRouteKey === 'releasing') {
                $_SESSION['success'] = "Document {$trackingCode} is now ready for releasing.";
                header("Location: /releasing");
            } else {
                $_SESSION['success'] = "Document {$trackingCode} has been received and added to your tasks.";
                header("Location: /tasks");
            }
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Action Denied')) {
                $_SESSION['console_error'] = $e->getMessage();
            } else {
                $_SESSION['error'] = $e->getMessage();
            }
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/'));
        }
        exit;
    }

    public function decline()
    {
        $db = \App\Common\Database\Database::getInstance();
        $documentId = $_POST['document_id'] ?? null;
        $reason = $_POST['reason'] ?? '';
        $submittedPin = $_POST['pin'] ?? '';
        $pin = \App\Modules\Users\SecurityPinCacheService::resolvePin($submittedPin);
        
        if (!$documentId || !$reason) {
            $_SESSION['error'] = 'Document ID and decline reason are required.';
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/intake'));
            exit;
        }

        if (empty($pin)) {
            $_SESSION['error'] = "Security PIN is required.";
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? '/'));
            exit;
        }

        try {
            $workflow = new \App\Modules\Documents\DocumentWorkflowService();
            $officer = \App\Modules\Users\UserModel::findById($_SESSION['user_id']);
            
            $doc = \App\Modules\Documents\DocumentModel::findById((int)$documentId);
            $trackingCode = $doc ? $doc->tracking_code : '';
            $workflow->declineDocument((int)$documentId, $reason, $officer, $pin);
            
            $_SESSION['success'] = "Document {$trackingCode} successfully declined.";
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Action Denied')) {
                $_SESSION['console_error'] = $e->getMessage();
            } else {
                $_SESSION['error'] = $e->getMessage();
            }
        }
        
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/intake'));
        exit;
    }

    /**
     * Generate a printable PDF tracking form for the specified document.
     *
     * @param string $tracking_code
     */
    public function printTrackingForm($tracking_code)
    {
        $db = Database::getInstance();
        
        $stmt = $db->query("SELECT d.*, p.name as purpose_name 
                            FROM documents d 
                            LEFT JOIN purposes p ON d.purpose_id = p.id 
                            WHERE d.tracking_code = :tracking_code", [':tracking_code' => $tracking_code]);
        $document = $stmt->fetch();

        if (!$document) {
            header("HTTP/1.0 404 Not Found");
            echo "Document not found.";
            exit;
        }

        // Generate QR Code
        $qrOptions = new QROptions([
            'version'         => 5,
            'outputInterface' => \chillerlan\QRCode\Output\QRGdImagePNG::class,
            'eccLevel'        => \chillerlan\QRCode\Common\EccLevel::L,
            'scale'           => 5,
            'outputBase64'    => true,
        ]);
        
        $qrCode = (new QRCode($qrOptions))->render($document['tracking_code']);
        $qrCodeBase64 = preg_replace('#^data:image/[^;]+;base64,#', '', $qrCode);

        // Capture HTML view
        ob_start();
        require BASE_PATH . '/src/Views/general/tracking-form-pdf.php';
        $html = ob_get_clean();

        // Generate PDF
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $dompdf->stream('document-tracking-form-'.$document['tracking_code'].'.pdf', ["Attachment" => false]);
    }
}
