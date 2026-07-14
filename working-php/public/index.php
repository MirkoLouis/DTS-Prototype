<?php

// Front Controller
session_start();

// Match Laravel's default timezone so hashes align properly
date_default_timezone_set('Asia/Manila');

// Setup Error Reporting (Development Mode)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Base Path Definition
define('BASE_PATH', dirname(__DIR__));

// Require Composer Autoloader
require BASE_PATH . '/vendor/autoload.php';

use App\Core\Router;

// Initialize the Custom Router
$router = new Router();

// --- Define Routes Below ---



// --- Auth Routes ---
$router->get('/login', [App\Controllers\AuthController::class, 'showLogin']);
$router->post('/login', [App\Controllers\AuthController::class, 'login']);
$router->post('/logout', [App\Controllers\AuthController::class, 'logout']);

// --- Guest / Public Routes ---
$router->get('/', [App\Controllers\GuestController::class, 'welcome'], [
    App\Middleware\CacheMiddleware::class . ':55'
]);
$router->post('/submit-document', [App\Controllers\GuestController::class, 'store']);
$router->get('/success', [App\Controllers\GuestController::class, 'success']);
$router->get('/track', [App\Controllers\GuestController::class, 'track']);
$router->get('/api/track/module/(?P<tracking_code>[A-Za-z0-9\-]+)', [App\Controllers\GuestController::class, 'getTrackedDocumentModule']);
$router->get('/api/track/status', [App\Controllers\GuestController::class, 'getStatusUpdates']);

$router->get('/dashboard', [App\Controllers\OfficerController::class, 'dashboard'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':officer,staff'
]);

$router->get('/statistics', [App\Controllers\StatisticsController::class, 'index'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin,officer,staff',
    App\Middleware\CacheMiddleware::class . ':55'
]);

// --- Protected Routes ---

$router->get('/system-overview', [App\Controllers\SystemHealthController::class, 'index'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);

$router->get('/all-documents', [App\Controllers\IntegrityMonitorController::class, 'index'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);


$router->post('/system-health/run-check', [App\Controllers\SystemHealthController::class, 'runIntegrityCheck'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);
$router->get('/system-health/debug-log/(?P<id>\d+)', [App\Controllers\SystemHealthController::class, 'debugLog'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);
$router->get('/api/system-health/integrity-status/(?P<jobId>[a-zA-Z0-9_-]+)', [App\Controllers\SystemHealthController::class, 'getIntegrityCheckStatus'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);
$router->post('/api/system-health/integrity-cancel/(?P<jobId>[a-zA-Z0-9_-]+)', [App\Controllers\SystemHealthController::class, 'cancelIntegrityCheck'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);
$router->get('/system-health/results', [App\Controllers\SystemHealthController::class, 'getIntegrityCheckResults'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);
$router->post('/system-health/rebuild-chain/(?P<logId>\d+)', [App\Controllers\SystemHealthController::class, 'rebuildChain'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);
$router->get('/api/system-health/db-performance', [App\Controllers\SystemHealthController::class, 'getDbPerformanceData'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);
$router->get('/admin/system-health/export-db-metrics', [App\Controllers\SystemHealthController::class, 'exportDbPerformanceMetrics'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);
$router->post('/system-health/failed-jobs/(?P<id>\d+)/delete', [App\Controllers\SystemHealthController::class, 'deleteFailedJob'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);
$router->post('/system-health/failed-jobs/delete-all', [App\Controllers\SystemHealthController::class, 'deleteAllFailedJobs'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);

$router->get('/system/backups', [App\Controllers\BackupManagerController::class, 'index'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);
$router->post('/system/backups/create', [App\Controllers\BackupManagerController::class, 'create'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);
$router->post('/system/backups/restore/(?P<fileName>.+)', [App\Controllers\BackupManagerController::class, 'restore'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);
$router->post('/system/backups/delete/(?P<fileName>.+)', [App\Controllers\BackupManagerController::class, 'delete'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);
$router->get('/system/backups/download/(?P<fileName>.+)', [App\Controllers\BackupManagerController::class, 'download'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);

$router->get('/integrity-monitor', [App\Controllers\IntegrityMonitorController::class, 'index'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);

// Profile & Security
$router->get('/profile', [App\Controllers\ProfileController::class, 'edit'], [
    App\Middleware\AuthMiddleware::class
]);
$router->post('/profile/update', [App\Controllers\ProfileController::class, 'update'], [
    App\Middleware\AuthMiddleware::class
]);
$router->post('/security-key', [App\Controllers\SecurityKeyController::class, 'store'], [
    App\Middleware\AuthMiddleware::class
]);

// Admin Dashboard Routes
$router->get('/admin-dashboard', [\App\Controllers\AdminDashboardController::class, 'index'], [
    App\Middleware\AuthMiddleware::class, 
    App\Middleware\RoleMiddleware::class . ':admin',
    App\Middleware\CacheMiddleware::class . ':55'
]);
$router->post('/admin-dashboard/clear-cache', [\App\Controllers\AdminDashboardController::class, 'clearCache'], [
    App\Middleware\AuthMiddleware::class, 
    App\Middleware\RoleMiddleware::class . ':admin'
]);
$router->post('/clear-personal-cache', [App\Controllers\SystemHealthController::class, 'clearPersonalCache'], [
    App\Middleware\AuthMiddleware::class
]);

// Admin Dashboard API Routes
$router->get('/api/admin-dashboard/current-load', [\App\Controllers\AdminDashboardController::class, 'getCurrentLoadData']);
$router->get('/api/admin-dashboard/throughput', [\App\Controllers\AdminDashboardController::class, 'getThroughputData'], [App\Middleware\AuthMiddleware::class, App\Middleware\RoleMiddleware::class . ':admin']);
$router->get('/api/admin-dashboard/return-decline-trends', [\App\Controllers\AdminDashboardController::class, 'getReturnDeclineTrendData'], [App\Middleware\AuthMiddleware::class, App\Middleware\RoleMiddleware::class . ':admin']);
$router->get('/api/admin-dashboard/status-distribution', [\App\Controllers\AdminDashboardController::class, 'getDocumentStatusDistributionData'], [App\Middleware\AuthMiddleware::class, App\Middleware\RoleMiddleware::class . ':admin']);
$router->get('/api/admin-dashboard/return-request-sources', [\App\Controllers\AdminDashboardController::class, 'getReturnRequestSourcesData'], [App\Middleware\AuthMiddleware::class, App\Middleware\RoleMiddleware::class . ':admin']);
$router->get('/api/admin-dashboard/processing-hotspots', [\App\Controllers\AdminDashboardController::class, 'getProcessingHotspotsData'], [App\Middleware\AuthMiddleware::class, App\Middleware\RoleMiddleware::class . ':admin']);
$router->get('/api/admin-dashboard/submission-districts', [\App\Controllers\AdminDashboardController::class, 'getSubmissionDistrictsData'], [App\Middleware\AuthMiddleware::class, App\Middleware\RoleMiddleware::class . ':admin']);
$router->get('/api/admin-dashboard/avg-step-time', [\App\Controllers\AdminDashboardController::class, 'getAvgStepTimeByDepartmentData'], [App\Middleware\AuthMiddleware::class, App\Middleware\RoleMiddleware::class . ':admin']);
$router->get('/api/admin-dashboard/department-load-vs-time', [\App\Controllers\AdminDashboardController::class, 'getDepartmentalLoadVsTimeData'], [App\Middleware\AuthMiddleware::class, App\Middleware\RoleMiddleware::class . ':admin']);

// Notifications
$router->post('/api/notifications/mark-read', [App\Controllers\NotificationController::class, 'markAsRead'], [
    App\Middleware\AuthMiddleware::class
]);

// Users Management
$router->get('/users', [App\Controllers\UserController::class, 'index'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);

$router->get('/users/create', [App\Controllers\UserController::class, 'create'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);

$router->post('/users', [App\Controllers\UserController::class, 'store'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);

$router->get('/users/(?P<id>\d+)/edit', [App\Controllers\UserController::class, 'edit'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);

$router->post('/users/(?P<id>\d+)/update', [App\Controllers\UserController::class, 'update'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);

$router->post('/users/(?P<id>\d+)/delete', [App\Controllers\UserController::class, 'destroy'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);

$router->post('/users/(?P<id>\d+)/reset-signature', [App\Controllers\UserController::class, 'resetSignature'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);

// Officer Routes
$router->get('/intake', [App\Controllers\IntakeController::class, 'index'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':officer'
]);

$router->post('/intake/find', [App\Controllers\DocumentController::class, 'find'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':officer'
]);

$router->get('/documents/(?P<id>\d+)/manage', [App\Controllers\DocumentController::class, 'manage'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':officer'
]);

$router->get('/documents/(?P<tracking_code>[A-Za-z0-9\-]+)', [App\Controllers\DocumentController::class, 'show']);
$router->get('/documents/(?P<tracking_code>[A-Za-z0-9\-]+)/hash-chain', [App\Controllers\DocumentController::class, 'showHashChain']);
$router->get('/documents/(?P<tracking_code>[A-Za-z0-9\-]+)/print-tracking-form', [App\Controllers\DocumentController::class, 'printTrackingForm']);

$router->post('/documents/(?P<tracking_code>[A-Za-z0-9\-]+)/freeze', [App\Controllers\SystemHealthController::class, 'freeze'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);
$router->post('/documents/(?P<tracking_code>[A-Za-z0-9\-]+)/unfreeze', [App\Controllers\SystemHealthController::class, 'unfreeze'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);
$router->post('/documents/(?P<tracking_code>[A-Za-z0-9\-]+)/autoresolve', [App\Controllers\SystemHealthController::class, 'autoResolve'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin'
]);

$router->post('/documents/(?P<id>\d+)/finalize', [App\Controllers\DocumentController::class, 'finalize'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin,officer'
]);

$router->post('/documents/decline', [App\Controllers\DocumentController::class, 'decline'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':admin,officer'
]);

$router->get('/tasks/completed', [App\Controllers\DashboardController::class, 'officerCompletedTasks'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':officer,staff'
]);

$router->get('/releasing', [App\Controllers\DashboardController::class, 'officerReleasing'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':officer'
]);

$router->post('/releasing/(?P<id>\d+)/complete', [App\Controllers\ReleasingController::class, 'complete'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':officer'
]);

$router->get('/return-requests', [App\Controllers\ReturnRequestController::class, 'index'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':officer,staff'
]);
$router->post('/return-requests', [App\Controllers\ReturnRequestController::class, 'store'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':officer,staff'
]);

$router->get('/statistics', [App\Controllers\StatisticsController::class, 'index'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':officer,staff',
    App\Middleware\CacheMiddleware::class . ':55'
]);
$router->post('/statistics/report', [App\Controllers\StatisticsController::class, 'generateReport'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':officer,staff'
]);
$router->get('/statistics/report/status', [App\Controllers\StatisticsController::class, 'getReportStatus'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':officer,staff'
]);
$router->post('/statistics/report/cancel', [App\Controllers\StatisticsController::class, 'cancelReport'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':officer,staff'
]);
$router->get('/statistics/report/download/(?P<jobId>[a-zA-Z0-9_-]+)', [App\Controllers\StatisticsController::class, 'downloadReport'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':officer,staff'
]);

// Chart API Routes
$router->get('/api/statistics/current-load', [App\Controllers\StatisticsController::class, 'getCurrentLoadData'], [
    App\Middleware\AuthMiddleware::class
]);
$router->get('/api/statistics/throughput', [App\Controllers\StatisticsController::class, 'getThroughputData'], [
    App\Middleware\AuthMiddleware::class
]);
$router->get('/api/statistics/avg-processing-time', [App\Controllers\StatisticsController::class, 'getAverageProcessingTimeData'], [
    App\Middleware\AuthMiddleware::class
]);

// Staff Routes (Officers also have access to tasks)
$router->get('/tasks', [App\Controllers\DashboardController::class, 'staffTasks'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':staff,officer'
]);

$router->post('/tasks/(?P<id>\d+)/complete', [App\Controllers\TaskController::class, 'complete'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':staff,officer'
]);

// Global Scan Receiver (For both staff and officers)
$router->post('/documents/scan', [App\Controllers\DocumentController::class, 'scan'], [
    App\Middleware\AuthMiddleware::class,
    App\Middleware\RoleMiddleware::class . ':staff,officer'
]);

// Test route to check database connectivity
$router->get('/documents', [App\Controllers\DocumentTestController::class, 'index']);

// $router->get('/tasks', [App\Controllers\TaskController::class, 'index'], [App\Middleware\AuthMiddleware::class]);

// ---------------------------

// Dispatch the Request
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

file_put_contents('/tmp/router.log', "URI: $uri, Method: $method, Path: " . (parse_url($uri, PHP_URL_PATH) ?? '/') . "\n", FILE_APPEND);

// If running PHP built-in server and file exists, return false to serve it statically
if (php_sapi_name() === 'cli-server' && is_file(__DIR__ . parse_url($uri, PHP_URL_PATH))) {
    return false;
}

$router->dispatch($uri, $method);
