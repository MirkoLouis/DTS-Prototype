<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\IntakeController;
use App\Http\Controllers\IntegrityMonitorController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReleasingController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\SystemRatingsController;
use App\Http\Controllers\BackupManagerController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\StatisticsController;
use Illuminate\Support\Facades\Route;


// Guest-facing routes
Route::get('/', [GuestController::class, 'welcome'])->name('welcome');
Route::post('/submit-document', [GuestController::class, 'store'])->name('document.store');
Route::get('/success/{tracking_code}/{document_id}', [GuestController::class, 'success'])->name('success');
Route::get('/track', [GuestController::class, 'track'])->name('track'); // Modified to accept query parameter

// API route for fetching single document module via AJAX
Route::get('/api/track-document/{tracking_code}', [GuestController::class, 'getTrackedDocumentModule']);

// API route for AJAX polling to get status updates
Route::get('/api/document-status', [GuestController::class, 'getStatusUpdates'])->name('api.document.status');

// Public route for submitting a rating
Route::post('/documents/{document:tracking_code}/rate', [DocumentController::class, 'rate'])->name('documents.rate');
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
    Route::get('/intake', [IntakeController::class, 'index'])->name('intake');
    Route::post('/intake/find', [IntakeController::class, 'find'])->name('intake.find');
    
    // Officer-specific Task routes
    Route::get('/officer-tasks', [TaskController::class, 'index'])->name('officer.tasks');
    Route::post('/officer-tasks/{document}/complete', [TaskController::class, 'complete'])->name('officer.tasks.complete');
    Route::get('/officer-completed-tasks', [TaskController::class, 'completed'])->name('officer.tasks.completed');
    
    // Releasing routes
    Route::get('/releasing', [ReleasingController::class, 'index'])->name('releasing');
    Route::post('/releasing/receive', [ReleasingController::class, 'receive'])->name('releasing.receive');
    Route::post('/releasing/{document}/complete', [ReleasingController::class, 'complete'])->name('releasing.complete');
});

// Specific routes for Staff
Route::middleware(['auth', 'role:staff'])->group(function () {
    Route::get('/staff-tasks', [TaskController::class, 'index'])->name('staff.tasks');
    Route::post('/staff-tasks/{document}/complete', [TaskController::class, 'complete'])->name('staff.tasks.complete');
    Route::get('/staff-completed-tasks', [TaskController::class, 'completed'])->name('staff.tasks.completed');
});

Route::middleware(['auth', 'role:officer,staff'])->group(function () {
    // Return Request routes
    Route::get('/return-requests', [\App\Http\Controllers\ReturnRequestController::class, 'index'])->name('return-requests.index');
    Route::post('/return-requests', [\App\Http\Controllers\ReturnRequestController::class, 'store'])->name('return-requests.store');

    // Statistics routes
    Route::get('/statistics', [\App\Http\Controllers\StatisticsController::class, 'index'])->name('statistics.index');
    Route::get('/api/statistics/throughput', [\App\Http\Controllers\StatisticsController::class, 'getThroughputData'])->name('api.statistics.throughput');
    Route::get('/api/statistics/current-load', [\App\Http\Controllers\StatisticsController::class, 'getCurrentLoadData'])->name('api.statistics.current-load');
    Route::get('/api/statistics/avg-processing-time', [\App\Http\Controllers\StatisticsController::class, 'getAverageProcessingTimeData'])->name('api.statistics.avg-processing-time');
    Route::post('/statistics/generate-report', [\App\Http\Controllers\StatisticsController::class, 'generateReport'])->name('statistics.generate-report');
    Route::get('/api/statistics/report-status/{jobId}', [\App\Http\Controllers\StatisticsController::class, 'getReportStatus'])->name('api.statistics.report-status');
    Route::post('/api/statistics/report-cancel/{jobId}', [\App\Http\Controllers\StatisticsController::class, 'cancelReport'])->name('api.statistics.report-cancel');
    Route::get('/statistics/report/download/{jobId}', [\App\Http\Controllers\StatisticsController::class, 'downloadReport'])->name('statistics.report.download');
    Route::post('/api/statistics/report-count', [\App\Http\Controllers\StatisticsController::class, 'getReportCount'])->name('api.statistics.report-count');
});


Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::resource('users', \App\Http\Controllers\UserManagementController::class);
    // Admin specific routes
    Route::get('/integrity-monitor', [IntegrityMonitorController::class, 'index'])->name('integrity-monitor');
    Route::get('/admin-dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('/api/admin-dashboard/current-load', [AdminDashboardController::class, 'getCurrentLoadData'])->name('api.admin-dashboard.current-load');
    Route::get('/api/admin-dashboard/throughput', [AdminDashboardController::class, 'getThroughputData'])->name('api.admin-dashboard.throughput');
    Route::get('/api/admin-dashboard/return-decline-trends', [AdminDashboardController::class, 'getReturnDeclineTrendData'])->name('api.admin-dashboard.return-decline-trends');
    Route::get('/api/admin-dashboard/status-distribution', [AdminDashboardController::class, 'getDocumentStatusDistributionData'])->name('api.admin-dashboard.status-distribution');
    Route::get('/api/admin-dashboard/return-request-sources', [AdminDashboardController::class, 'getReturnRequestSourcesData'])->name('api.admin-dashboard.return-request-sources');
    Route::get('/api/admin-dashboard/processing-hotspots', [AdminDashboardController::class, 'getProcessingHotspotsData'])->name('api.admin-dashboard.processing-hotspots');
    Route::get('/api/admin-dashboard/submission-districts', [AdminDashboardController::class, 'getSubmissionDistrictsData'])->name('api.admin-dashboard.submission-districts');
    Route::get('/api/admin-dashboard/avg-step-time', [AdminDashboardController::class, 'getAvgStepTimeByDepartmentData'])->name('api.admin-dashboard.avg-step-time');
    Route::get('/api/admin-dashboard/department-load-vs-time', [AdminDashboardController::class, 'getDepartmentalLoadVsTimeData'])->name('api.admin-dashboard.department-load-vs-time');

    // System pages
    Route::get('/system-health', [SystemHealthController::class, 'index'])->name('system.health');
    Route::post('/system-health/run-check', [SystemHealthController::class, 'runIntegrityCheck'])->name('system.health.run-check');
    Route::get('/system-health/results', [SystemHealthController::class, 'getIntegrityCheckResults'])->name('system.health.results');
    Route::post('/system-health/rebuild-chain/{log}', [SystemHealthController::class, 'rebuildChain'])->name('system.health.rebuild-chain');
    Route::get('/api/system-health/db-performance', [SystemHealthController::class, 'getDbPerformanceData'])->name('api.system-health.db-performance');
    Route::get('/admin/system-health/export-db-metrics', [SystemHealthController::class, 'exportDbPerformanceMetrics'])->name('admin.system-health.export-db-metrics');
    Route::get('/system/ratings', [SystemRatingsController::class, 'index'])->name('system.ratings');

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

    // Route for handling QR code scans
    Route::post('/scan', [DocumentController::class, 'scan'])->name('documents.scan');
});



// Breeze's Authentication routes
require __DIR__.'/auth.php';