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
use Illuminate\Support\Facades\Route;


// Guest-facing routes
Route::get('/', [GuestController::class, 'welcome'])->name('welcome');
Route::post('/submit-document', [GuestController::class, 'store'])->name('document.store');
Route::get('/success/{tracking_code}', [GuestController::class, 'success'])->name('success');
Route::get('/track', [GuestController::class, 'track'])->name('track'); // Modified to accept query parameter

// API route for fetching single document module via AJAX
Route::get('/api/track-document/{tracking_code}', [GuestController::class, 'getTrackedDocumentModule']);

// API route for AJAX polling to get status updates
Route::get('/api/document-status', [GuestController::class, 'getStatusUpdates'])->name('api.document.status');

// Public route for submitting a rating
Route::post('/documents/{document:tracking_code}/rate', [DocumentController::class, 'rate'])->name('documents.rate');

// The main dashboard route, which redirects based on role.
// This replaces the default Breeze dashboard route.
Route::get('/dashboard', function () {
    // This route is protected by the 'auth' and 'role' middleware.
    // The 'role' middleware will handle the redirection.
    // We can just return a simple view here as a fallback.
    return view('dashboard');
})->middleware(['auth', 'verified', 'role'])->name('dashboard');


// Specific routes for each role's dashboard
Route::middleware('auth')->group(function() {
    Route::get('/intake', [IntakeController::class, 'index'])->name('intake');
    Route::post('/intake/find', [IntakeController::class, 'find'])->name('intake.find');
    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks');
    Route::post('/tasks/{document}/complete', [TaskController::class, 'complete'])->name('tasks.complete');

    // Releasing routes
    Route::get('/releasing', [ReleasingController::class, 'index'])->name('releasing');
    Route::post('/releasing/{document}/complete', [ReleasingController::class, 'complete'])->name('releasing.complete');
    
    // Admin specific routes
    Route::get('/integrity-monitor', [IntegrityMonitorController::class, 'index'])->name('integrity-monitor');
    Route::get('/admin-dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
    Route::get('/api/admin-dashboard/current-load', [AdminDashboardController::class, 'getCurrentLoadData'])->name('api.admin-dashboard.current-load');
    Route::get('/api/admin-dashboard/throughput', [AdminDashboardController::class, 'getThroughputData'])->name('api.admin-dashboard.throughput');

    // System pages
    Route::get('/system-health', [SystemHealthController::class, 'index'])->name('system.health');
    Route::post('/system-health/run-check', [SystemHealthController::class, 'runIntegrityCheck'])->name('system.health.run-check');
    Route::get('/system-health/results', [SystemHealthController::class, 'getIntegrityCheckResults'])->name('system.health.results');
    Route::post('/system-health/rebuild-chain/{log}', [SystemHealthController::class, 'rebuildChain'])->name('system.health.rebuild-chain');
    Route::get('/system/ratings', [SystemRatingsController::class, 'index'])->name('system.ratings');

    // Backup Manager routes
    Route::get('/system/backups', [BackupManagerController::class, 'index'])->name('system.backups.index');
    Route::post('/system/backups/create', [BackupManagerController::class, 'create'])->name('system.backups.create');
    Route::get('/system/backups/download/{fileName}', [BackupManagerController::class, 'download'])->name('system.backups.download');
    Route::delete('/system/backups/delete/{fileName}', [BackupManagerController::class, 'delete'])->name('system.backups.delete');
    Route::post('/system/backups/restore/{fileName}', [BackupManagerController::class, 'restore'])->name('system.backups.restore');

    // Document management routes
    Route::get('/documents/{document}/manage', [DocumentController::class, 'manage'])->name('documents.manage');
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::post('/documents/{document}/finalize', [DocumentController::class, 'finalize'])->name('documents.finalize');
    Route::post('/documents/{document}/freeze', [DocumentController::class, 'freeze'])->name('documents.freeze');
    Route::post('/documents/{document}/unfreeze', [DocumentController::class, 'unfreeze'])->name('documents.unfreeze');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
});


// Breeze's Profile routes
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Breeze's Authentication routes
require __DIR__.'/auth.php';