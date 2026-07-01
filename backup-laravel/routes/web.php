<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\IntakeController;
use App\Http\Controllers\IntegrityMonitorController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReleasingController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\SystemHealthController;

use App\Http\Controllers\BackupManagerController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\StatisticsController;
use Illuminate\Support\Facades\Route;


// Guest-facing routes
Route::middleware('cache.response:55')->group(function () {
    Route::get('/', [GuestController::class, 'welcome'])->name('welcome');
    Route::get('/success/{tracking_code}/{document_id}', [GuestController::class, 'success'])->name('success');

    // API route for fetching single document module via AJAX
    Route::get('/api/track-document/{tracking_code}', [GuestController::class, 'getTrackedDocumentModule']);

    // API route for AJAX polling to get status updates
    Route::get('/api/document-status', [GuestController::class, 'getStatusUpdates'])->name('api.document.status');
});

Route::get('/track', [GuestController::class, 'track'])->name('track'); 

Route::post('/submit-document', [GuestController::class, 'store'])->name('document.store');


Route::get('/documents/{document}/print-tracking-form', [DocumentController::class, 'printTrackingForm'])->name('documents.print-tracking-form');

// The main dashboard route, which redirects based on role.
// This replaces the default Breeze dashboard route.
Route::get('/dashboard', function () {
    // This route is protected by the 'auth' and 'role' middleware.
    // The 'role' middleware will handle the redirection.
    // We can just return a simple view here as a fallback.
    return view('general.dashboard');
})->middleware(['auth', 'verified', 'role'])->name('dashboard');


// Specific routes for each role's dashboard
Route::middleware(['auth', 'role:officer'])->group(function () {
    Route::middleware('cache.response:55')->group(function () {
        Route::get('/intake', [IntakeController::class, 'index'])->name('intake');
        Route::get('/releasing', [ReleasingController::class, 'index'])->name('releasing');
    });

    Route::post('/intake/find', [IntakeController::class, 'find'])->name('intake.find');
    Route::post('/releasing/receive', [ReleasingController::class, 'receive'])->name('releasing.receive');
    Route::post('/releasing/{document}/complete', [ReleasingController::class, 'complete'])->name('releasing.complete');
});

// Shared tasks routes for both staff and officer
Route::middleware(['auth', 'role:officer,staff'])->group(function () {
    Route::middleware('cache.response:55')->group(function () {
        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::get('/completed-tasks', [TaskController::class, 'completed'])->name('tasks.completed');
    });

    Route::post('/tasks/{document}/complete', [TaskController::class, 'complete'])->name('tasks.complete');

    // Return Request routes
    Route::get('/return-requests', [\App\Http\Controllers\ReturnRequestController::class, 'index'])->name('return-requests.index');
    Route::post('/return-requests', [\App\Http\Controllers\ReturnRequestController::class, 'store'])->name('return-requests.store');

    // Statistics routes
    Route::get('/statistics', [\App\Http\Controllers\StatisticsController::class, 'index'])->middleware('cache.response:55')->name('statistics.index');
    
    // Cached API routes for statistics
    Route::middleware('cache.response:55')->prefix('api/statistics')->name('api.statistics.')->group(function () {
        Route::get('/throughput', [\App\Http\Controllers\StatisticsController::class, 'getThroughputData'])->name('throughput');
        Route::get('/current-load', [\App\Http\Controllers\StatisticsController::class, 'getCurrentLoadData'])->name('current-load');
        Route::get('/avg-processing-time', [\App\Http\Controllers\StatisticsController::class, 'getAverageProcessingTimeData'])->name('avg-processing-time');
    });

    Route::post('/statistics/generate-report', [\App\Http\Controllers\StatisticsController::class, 'generateReport'])->name('statistics.generate-report');
    Route::get('/api/statistics/report-status/{jobId}', [\App\Http\Controllers\StatisticsController::class, 'getReportStatus'])->name('api.statistics.report-status');
    Route::post('/api/statistics/report-cancel/{jobId}', [\App\Http\Controllers\StatisticsController::class, 'cancelReport'])->name('api.statistics.report-cancel');
    Route::get('/statistics/report/download/{jobId}', [\App\Http\Controllers\StatisticsController::class, 'downloadReport'])->name('statistics.report.download');
    Route::post('/api/statistics/report-count', [\App\Http\Controllers\StatisticsController::class, 'getReportCount'])->name('api.statistics.report-count');
});


Route::middleware(['auth', 'role:admin'])->group(function () {
    // Admin specific routes
    Route::middleware('cache.response:55')->group(function () {
        Route::resource('users', \App\Http\Controllers\UserManagementController::class)->only(['index']);
        Route::get('/integrity-monitor', [IntegrityMonitorController::class, 'index'])->name('integrity-monitor');
        Route::get('/admin-dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');

    });

    Route::resource('users', \App\Http\Controllers\UserManagementController::class)->except(['index']);
    Route::post('/users/{user}/reset-signature', [\App\Http\Controllers\UserManagementController::class, 'resetSignature'])->name('users.reset-signature');
    
    Route::post('/admin-dashboard/clear-cache', [AdminDashboardController::class, 'clearCache'])->name('admin.dashboard.clear-cache');
    
    // Cached Admin API routes
    Route::middleware('cache.response:55')->prefix('api/admin-dashboard')->name('api.admin-dashboard.')->group(function () {
        Route::get('/current-load', [AdminDashboardController::class, 'getCurrentLoadData'])->name('current-load');
        Route::get('/throughput', [AdminDashboardController::class, 'getThroughputData'])->name('throughput');
        Route::get('/return-decline-trends', [AdminDashboardController::class, 'getReturnDeclineTrendData'])->name('return-decline-trends');
        Route::get('/status-distribution', [AdminDashboardController::class, 'getDocumentStatusDistributionData'])->name('status-distribution');
        Route::get('/return-request-sources', [AdminDashboardController::class, 'getReturnRequestSourcesData'])->name('return-request-sources');
        Route::get('/processing-hotspots', [AdminDashboardController::class, 'getProcessingHotspotsData'])->name('processing-hotspots');
        Route::get('/submission-districts', [AdminDashboardController::class, 'getSubmissionDistrictsData'])->name('submission-districts');
        Route::get('/avg-step-time', [AdminDashboardController::class, 'getAvgStepTimeByDepartmentData'])->name('avg-step-time');
        Route::get('/department-load-vs-time', [AdminDashboardController::class, 'getDepartmentalLoadVsTimeData'])->name('department-load-vs-time');
    });

    // System pages
    Route::get('/system-health', [SystemHealthController::class, 'index'])->name('system.health');
    Route::get('/system-health/debug-log/{log}', [SystemHealthController::class, 'debugLog'])->name('system.health.debug-log');
    Route::post('/system-health/run-check', [SystemHealthController::class, 'runIntegrityCheck'])->name('system.health.run-check');
    Route::get('/api/system-health/integrity-status/{jobId}', [SystemHealthController::class, 'getIntegrityCheckStatus'])->name('api.system-health.integrity-status');
    Route::post('/api/system-health/integrity-cancel/{jobId}', [SystemHealthController::class, 'cancelIntegrityCheck'])->name('api.system-health.integrity-cancel');
    Route::get('/system-health/results', [SystemHealthController::class, 'getIntegrityCheckResults'])->name('system.health.results');
    Route::post('/system-health/rebuild-chain/{log}', [SystemHealthController::class, 'rebuildChain'])->name('system.health.rebuild-chain');
    Route::get('/api/system-health/db-performance', [SystemHealthController::class, 'getDbPerformanceData'])->name('api.system-health.db-performance');
    Route::get('/admin/system-health/export-db-metrics', [SystemHealthController::class, 'exportDbPerformanceMetrics'])->name('admin.system-health.export-db-metrics');
    Route::delete('/system-health/failed-jobs/{id}', [SystemHealthController::class, 'deleteFailedJob'])->name('system.health.failed-jobs.delete');
    Route::delete('/system-health/failed-jobs', [SystemHealthController::class, 'deleteAllFailedJobs'])->name('system.health.failed-jobs.delete-all');

    // Backup Manager routes
    Route::get('/system/backups', [BackupManagerController::class, 'index'])->name('system.backups.index');
    Route::post('/system/backups/create', [BackupManagerController::class, 'create'])->name('system.backups.create');
    Route::get('/system/backups/download/{fileName}', [BackupManagerController::class, 'download'])->where('fileName', '.*')->name('system.backups.download');
    Route::delete('/system/backups/delete/{fileName}', [BackupManagerController::class, 'delete'])->where('fileName', '.*')->name('system.backups.delete');
    Route::post('/system/backups/restore/{fileName}', [BackupManagerController::class, 'restore'])->where('fileName', '.*')->name('system.backups.restore');
    
    // Admin-specific document actions
    Route::post('/documents/{document}/freeze', [DocumentController::class, 'freeze'])->name('documents.freeze');
    Route::post('/documents/{document}/unfreeze', [DocumentController::class, 'unfreeze'])->name('documents.unfreeze');
});

// Routes accessible by any authenticated user
Route::middleware('auth')->group(function() {
    // Document management routes
    Route::get('/documents/{document}/manage', [DocumentController::class, 'manage'])->name('documents.manage');
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::post('/documents/{document}/finalize', [DocumentController::class, 'finalize'])->name('documents.finalize');

    Route::post('/documents/{document}/decline', [DocumentController::class, 'decline'])->name('documents.decline');

    // New route for displaying the hash chain of a document
    Route::get('/documents/{document}/hash-chain', [DocumentController::class, 'showHashChain'])->name('documents.show-hash-chain');

    // Route for handling QR code scans
    Route::post('/scan', [DocumentController::class, 'scan'])->name('documents.scan');

    // Security Key Initialization
    Route::post('/security/key', [\App\Http\Controllers\SecurityKeyController::class, 'store'])->name('security.key.store');
});



// Breeze's Authentication routes
require __DIR__.'/auth.php';