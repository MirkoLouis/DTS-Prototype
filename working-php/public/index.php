<?php

$appStartTime = microtime(true);

// Front Controller & Session Hardening
session_start([
    'cookie_httponly' => true,
    'cookie_secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => true,
]);

// CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Match Laravel's default timezone so hashes align properly
date_default_timezone_set('Asia/Manila');

// Base Path Definition
define('BASE_PATH', dirname(__DIR__));

// Require Composer Autoloader & Custom Helpers
require BASE_PATH . '/vendor/autoload.php';

// Error reporting: always log everything, but only print errors to the page when APP_DEBUG=true
$appDebug = \App\Common\Config\Config::get('app', 'debug', false);
ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('display_startup_errors', $appDebug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
require BASE_PATH . '/src/Common/Utils/helpers.php';

use App\Common\Http\Router;

// Initialize the Custom Router
$router = new Router();

// --- Define Routes Below ---

// Test-only environment probe: registered only under APP_ENV=testing, so production 404s it like any unknown route
if (\App\Common\Config\Config::get('app', 'env') === 'testing') {
    $router->get('/__test/env', function () {
        header('Content-Type: application/json');
        echo json_encode([
            'env' => \App\Common\Config\Config::get('app', 'env'),
            'db' => \App\Common\Config\Config::get('database', 'dbname'),
        ]);
    });
}



// --- Auth Routes ---
$router->get('/login', [App\Modules\Auth\AuthController::class, 'showLogin']);
$router->post('/login', [App\Modules\Auth\AuthController::class, 'login']);
$router->post('/logout', [App\Modules\Auth\AuthController::class, 'logout']);

// --- Guest / Public Routes ---
$router->get('/', [App\Modules\Documents\GuestController::class, 'welcome'], [
    App\Common\Middleware\CacheMiddleware::class . ':55'
]);
$router->post('/submit-document', [App\Modules\Documents\GuestController::class, 'store']);
$router->get('/success', [App\Modules\Documents\GuestController::class, 'success']);
$router->get('/track', [App\Modules\Documents\GuestController::class, 'track']);
$router->get('/api/track/module/(?P<tracking_code>[A-Za-z0-9\-]+)', [App\Modules\Documents\GuestController::class, 'getTrackedDocumentModule']);
$router->get('/api/track/status', [App\Modules\Documents\GuestController::class, 'getStatusUpdates']);

// Role-based dashboard redirect — routes each role to its dedicated landing page.
$router->get('/dashboard', function() {
    $role = $_SESSION['role'] ?? 'staff';
    $destinations = [
        'admin'   => '/admin-dashboard',
        'officer' => '/intake',
        'staff'   => '/tasks',
    ];
    header('Location: ' . ($destinations[$role] ?? '/tasks'));
    exit;
}, [
    App\Common\Middleware\AuthMiddleware::class,
]);

$router->get('/statistics', [App\Modules\Analytics\StatisticsController::class, 'index'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin,officer,staff',
    App\Common\Middleware\CacheMiddleware::class . ':55'
]);

// --- Protected Routes ---

$router->get('/system-overview', [App\Modules\System\SystemHealthController::class, 'index'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);

$router->get('/all-documents', [App\Modules\Integrity\IntegrityMonitorController::class, 'index'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);


$router->post('/system-health/run-check', [App\Modules\System\SystemHealthController::class, 'runIntegrityCheck'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);
$router->get('/system-health/debug-log/(?P<id>\d+)', [App\Modules\System\SystemHealthController::class, 'debugLog'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);
$router->get('/api/system-health/integrity-status/(?P<jobId>[a-zA-Z0-9_-]+)', [App\Modules\System\SystemHealthController::class, 'getIntegrityCheckStatus'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);
$router->post('/api/system-health/integrity-cancel/(?P<jobId>[a-zA-Z0-9_-]+)', [App\Modules\System\SystemHealthController::class, 'cancelIntegrityCheck'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);
$router->get('/system-health/results', [App\Modules\System\SystemHealthController::class, 'getIntegrityCheckResults'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);
$router->get('/api/system-health/db-performance', [App\Modules\System\SystemHealthController::class, 'getDbPerformanceData'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);
$router->get('/admin/system-health/export-db-metrics', [App\Modules\System\SystemHealthController::class, 'exportDbPerformanceMetrics'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);
$router->post('/system-health/failed-jobs/(?P<id>\d+)/delete', [App\Modules\System\SystemHealthController::class, 'deleteFailedJob'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);
$router->post('/system-health/failed-jobs/delete-all', [App\Modules\System\SystemHealthController::class, 'deleteAllFailedJobs'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);
$router->post('/system-health/freeze-all', [App\Modules\System\SystemHealthController::class, 'freezeAll'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);

$router->get('/system/backups', [App\Modules\System\BackupManagerController::class, 'index'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);
$router->post('/system/backups/create', [App\Modules\System\BackupManagerController::class, 'create'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);
$router->post('/system/backups/restore/(?P<fileName>.+)', [App\Modules\System\BackupManagerController::class, 'restore'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);
$router->post('/system/backups/delete/(?P<fileName>.+)', [App\Modules\System\BackupManagerController::class, 'delete'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);
$router->get('/system/backups/download/(?P<fileName>.+)', [App\Modules\System\BackupManagerController::class, 'download'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);

// Legacy route alias redirecting to primary documents & integrity monitor view
$router->get('/integrity-monitor', function() {
    header('Location: /all-documents', true, 301);
    exit;
}, [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);

// Profile & Security
$router->get('/profile', [App\Modules\Users\ProfileController::class, 'edit'], [
    App\Common\Middleware\AuthMiddleware::class
]);
$router->post('/profile/update', [App\Modules\Users\ProfileController::class, 'update'], [
    App\Common\Middleware\AuthMiddleware::class
]);
$router->post('/security-key', [App\Modules\Users\SecurityKeyController::class, 'store'], [
    App\Common\Middleware\AuthMiddleware::class
]);

// Admin Dashboard Routes
$router->get('/admin-dashboard', [\App\Modules\Analytics\AdminDashboardController::class, 'index'], [
    App\Common\Middleware\AuthMiddleware::class, 
    App\Common\Guards\RoleGuard::class . ':admin',
    App\Common\Middleware\CacheMiddleware::class . ':55'
]);
$router->post('/admin-dashboard/clear-cache', [\App\Modules\Analytics\AdminDashboardController::class, 'clearCache'], [
    App\Common\Middleware\AuthMiddleware::class, 
    App\Common\Guards\RoleGuard::class . ':admin'
]);
$router->post('/clear-personal-cache', [App\Modules\System\SystemHealthController::class, 'clearPersonalCache'], [
    App\Common\Middleware\AuthMiddleware::class
]);

// Admin Dashboard API Routes
$router->get('/api/admin-dashboard/current-load', [\App\Modules\Analytics\AdminDashboardController::class, 'getCurrentLoadData'], [App\Common\Middleware\AuthMiddleware::class, App\Common\Guards\RoleGuard::class . ':admin']);
$router->get('/api/admin-dashboard/throughput', [\App\Modules\Analytics\AdminDashboardController::class, 'getThroughputData'], [App\Common\Middleware\AuthMiddleware::class, App\Common\Guards\RoleGuard::class . ':admin']);
$router->get('/api/admin-dashboard/decline-trends', [\App\Modules\Analytics\AdminDashboardController::class, 'getDeclineTrendData'], [App\Common\Middleware\AuthMiddleware::class, App\Common\Guards\RoleGuard::class . ':admin']);
$router->get('/api/admin-dashboard/status-distribution', [\App\Modules\Analytics\AdminDashboardController::class, 'getDocumentStatusDistributionData'], [App\Common\Middleware\AuthMiddleware::class, App\Common\Guards\RoleGuard::class . ':admin']);
$router->get('/api/admin-dashboard/processing-hotspots', [\App\Modules\Analytics\AdminDashboardController::class, 'getProcessingHotspotsData'], [App\Common\Middleware\AuthMiddleware::class, App\Common\Guards\RoleGuard::class . ':admin']);
$router->get('/api/admin-dashboard/submission-districts', [\App\Modules\Analytics\AdminDashboardController::class, 'getSubmissionDistrictsData'], [App\Common\Middleware\AuthMiddleware::class, App\Common\Guards\RoleGuard::class . ':admin']);
$router->get('/api/admin-dashboard/avg-step-time', [\App\Modules\Analytics\AdminDashboardController::class, 'getAvgStepTimeByDepartmentData'], [App\Common\Middleware\AuthMiddleware::class, App\Common\Guards\RoleGuard::class . ':admin']);
$router->get('/api/admin-dashboard/department-load-vs-time', [\App\Modules\Analytics\AdminDashboardController::class, 'getDepartmentalLoadVsTimeData'], [App\Common\Middleware\AuthMiddleware::class, App\Common\Guards\RoleGuard::class . ':admin']);
$router->get('/api/admin-dashboard/peak-intake-hours', [\App\Modules\Analytics\AdminDashboardController::class, 'getPeakIntakeHoursData'], [App\Common\Middleware\AuthMiddleware::class, App\Common\Guards\RoleGuard::class . ':admin']);

// Notifications
$router->get('/api/notifications/unread', [App\Controllers\NotificationController::class, 'getUnread'], [
    App\Common\Middleware\AuthMiddleware::class
]);
$router->post('/api/notifications/mark-read', [App\Controllers\NotificationController::class, 'markAsRead'], [
    App\Common\Middleware\AuthMiddleware::class
]);

// Users Management (RESTful & Form POST aliases)
$router->get('/users', [App\Modules\Users\UserController::class, 'index'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);

$router->get('/users/create', [App\Modules\Users\UserController::class, 'create'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);

$router->post('/users', [App\Modules\Users\UserController::class, 'store'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);

$router->get('/users/(?P<id>\d+)/edit', [App\Modules\Users\UserController::class, 'edit'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);

$router->post('/users/(?P<id>\d+)/update', [App\Modules\Users\UserController::class, 'update'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);

$router->add('PUT', '/users/(?P<id>\d+)', [App\Modules\Users\UserController::class, 'update'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);

$router->post('/users/(?P<id>\d+)/delete', [App\Modules\Users\UserController::class, 'destroy'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);

$router->add('DELETE', '/users/(?P<id>\d+)', [App\Modules\Users\UserController::class, 'destroy'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);

$router->post('/users/(?P<id>\d+)/reset-signature', [App\Modules\Users\UserController::class, 'resetSignature'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);

// Officer Routes
$router->get('/intake', [App\Modules\Documents\IntakeController::class, 'index'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':officer'
]);

$router->post('/intake/find', [App\Modules\Documents\DocumentController::class, 'find'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':officer'
]);

$router->get('/documents/(?P<id>\d+)/manage', [App\Modules\Documents\DocumentController::class, 'manage'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':officer'
]);

$router->get('/documents/(?P<tracking_code>[A-Za-z0-9\-]+)', [App\Modules\Documents\DocumentController::class, 'show'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin,officer,staff'
]);
$router->get('/documents/(?P<tracking_code>[A-Za-z0-9\-]+)/hash-chain', [App\Modules\Documents\DocumentController::class, 'showHashChain'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin,officer,staff'
]);
$router->get('/documents/(?P<tracking_code>[A-Za-z0-9\-]+)/print-tracking-form', [App\Modules\Documents\DocumentController::class, 'printTrackingForm']);

$router->post('/documents/(?P<tracking_code>[A-Za-z0-9\-]+)/freeze', [App\Modules\System\SystemHealthController::class, 'freeze'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);
$router->post('/documents/(?P<tracking_code>[A-Za-z0-9\-]+)/unfreeze', [App\Modules\System\SystemHealthController::class, 'unfreeze'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);
$router->post('/documents/(?P<tracking_code>[A-Za-z0-9\-]+)/autoresolve', [App\Modules\System\SystemHealthController::class, 'autoResolve'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin'
]);

$router->post('/documents/(?P<id>\d+)/finalize', [App\Modules\Documents\DocumentController::class, 'finalize'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin,officer'
]);

$router->post('/documents/decline', [App\Modules\Documents\DocumentController::class, 'decline'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':admin,officer'
]);

$router->get('/tasks/completed', [App\Modules\Documents\DashboardController::class, 'officerCompletedTasks'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':officer,staff'
]);

$router->get('/releasing', [App\Modules\Documents\DashboardController::class, 'officerReleasing'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':officer'
]);

$router->post('/releasing/(?P<id>\d+)/complete', [App\Modules\Documents\ReleasingController::class, 'complete'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':officer'
]);


$router->post('/statistics/report', [App\Modules\Analytics\StatisticsController::class, 'generateReport'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':officer,staff'
]);
$router->get('/statistics/report/status', [App\Modules\Analytics\StatisticsController::class, 'getReportStatus'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':officer,staff'
]);
$router->post('/statistics/report/cancel', [App\Modules\Analytics\StatisticsController::class, 'cancelReport'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':officer,staff'
]);
$router->get('/statistics/report/download/(?P<jobId>[a-zA-Z0-9_-]+)', [App\Modules\Analytics\StatisticsController::class, 'downloadReport'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':officer,staff'
]);

// Chart API Routes
$router->get('/api/statistics/current-load', [App\Modules\Analytics\StatisticsController::class, 'getCurrentLoadData'], [
    App\Common\Middleware\AuthMiddleware::class
]);
$router->get('/api/statistics/throughput', [App\Modules\Analytics\StatisticsController::class, 'getThroughputData'], [
    App\Common\Middleware\AuthMiddleware::class
]);
$router->get('/api/statistics/avg-processing-time', [App\Modules\Analytics\StatisticsController::class, 'getAverageProcessingTimeData'], [
    App\Common\Middleware\AuthMiddleware::class
]);
$router->get('/api/statistics/past-reports', [App\Modules\Analytics\StatisticsController::class, 'getPastReports'], [
    App\Common\Middleware\AuthMiddleware::class
]);

// Staff Routes (Officers also have access to tasks)
$router->get('/tasks', [App\Modules\Documents\DashboardController::class, 'staffTasks'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':staff,officer'
]);

$router->post('/tasks/(?P<id>\d+)/complete', [App\Modules\Documents\TaskController::class, 'complete'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':staff,officer'
]);

// Global Scan Receiver (For both staff and officers)
$router->post('/documents/scan', [App\Modules\Documents\DocumentController::class, 'scan'], [
    App\Common\Middleware\AuthMiddleware::class,
    App\Common\Guards\RoleGuard::class . ':staff,officer'
]);

// ---------------------------


// Dispatch the Request
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// If running PHP built-in server and file exists, return false to serve it statically
if (php_sapi_name() === 'cli-server' && is_file(__DIR__ . parse_url($uri, PHP_URL_PATH))) {
    return false;
}

$router->dispatch($uri, $method);

// Log Navigation Performance
$appEndTime = microtime(true);
$durationMs = round(($appEndTime - $appStartTime) * 1000);
$referer = $_SERVER['HTTP_REFERER'] ?? 'direct';
$refererPath = $referer !== 'direct' ? (parse_url($referer, PHP_URL_PATH) ?? '/') : 'direct';
$currentPath = parse_url($uri, PHP_URL_PATH) ?? '/';

$logDir = BASE_PATH . '/storage/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}
$logMessage = sprintf("[%s] %s to %s - %dms\n", date('Y-m-d H:i:s'), $refererPath, $currentPath, $durationMs);
file_put_contents($logDir . '/navigation.log', $logMessage, FILE_APPEND);
