<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\IntakeController;
use App\Http\Controllers\IntegrityMonitorController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TaskController;
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
    Route::get('/integrity-monitor', [IntegrityMonitorController::class, 'index'])->name('integrity-monitor');

    // Document management routes
    Route::get('/documents/{document}/manage', [DocumentController::class, 'manage'])->name('documents.manage');
    Route::post('/documents/{document}/finalize', [DocumentController::class, 'finalize'])->name('documents.finalize');
});


// Breeze's Profile routes
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Breeze's Authentication routes
require __DIR__.'/auth.php';