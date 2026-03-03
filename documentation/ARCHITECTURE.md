# DTS Architecture & Core Logic

## Summary
A comprehensive technical deep-dive into the foundations of the Document Tracking System (DTS). This document covers the security models, AI-driven routing, document lifecycle management, and the architectural safeguards that ensure system integrity and resilience.

## Table of Contents
1. [Role-Based Access Control (RBAC)](#1-role-based-access-control-rbac)
2. [Document Lifecycle & Statuses](#2-document-lifecycle--statuses)
3. [AI Route Prediction Engine](#3-ai-route-prediction-engine)
4. [Document Hashing & Integrity (The Trust Builder)](#4-document-hashing--integrity-the-trust-builder)
5. [Resilience & Fallback Strategies](#5-resilience--fallback-strategies)

---

## 1. Role-Based Access Control (RBAC)

RBAC is the security foundation of the DTS, ensuring that users only access the data and actions necessary for their specific job functions.

### Implementation: The Traffic Cop (Middleware)
The core logic resides in `app/Http/Middleware/RoleMiddleware.php`. This middleware performs two vital functions: initial login redirection and route protection.

```php
// app/Http/Middleware/RoleMiddleware.php
public function handle(Request $request, Closure $next, ...$roles)
{
    $user = $request->user();

    // Traffic Cop: Redirect to role-specific dashboard
    if ($request->is('dashboard')) {
        return match ($user->role) {
            'admin' => redirect()->route('admin.dashboard'),
            'officer' => redirect()->route('officer.intake'),
            'staff' => redirect()->route('staff.tasks'),
            default => abort(403),
        };
    }

    // Gatekeeper: Route Protection
    if (!in_array($user->role, $roles)) {
        abort(403, 'Unauthorized access.');
    }

    return $next($request);
}
```

### User Management & Automated Permissions
When an Administrator creates a new user, the system automatically handles department assignment and digital signature initialization, ensuring the user is immediately ready for the document workflow.

---

## 2. Document Lifecycle & Statuses

The document lifecycle follows a structured path, moving from public submission to multi-department processing, and finally back to a centralized release point.

### Detailed Status Definitions
- **`pending`**: Entry point. Awaiting Records Office intake.
- **`declined`**: Terminal state. Rejected during intake (e.g., missing attachments).
- **`in_transit`**: Physically moving between handlers.
- **`processing`**: Actively being worked on by a department.
- **`ready_for_release`**: All processing steps complete; returned to Records Office.
- **`completed`**: Terminal state. Physically handed back to the guest.
- **`frozen`**: Administrative pause for investigations or integrity errors.

### Non-Linearity: The "Return Request"
A department can return a document to a previous step. This is handled by resetting the `current_step` while maintaining the `in_transit` status, allowing the target department to re-scan and process the document.

---

## 3. AI Route Prediction Engine

A hybrid expert system that predicts a departmental sequence (route) based on weighted keyword correlations.

### Implementation: Weighted Scoring
The `RoutePredictionService` tokenizes the input and performs a weighted sum across the `prediction_keywords` table.

```php
// app/Services/RoutePredictionService.php
public function predict(string $purposeText): array
{
    $tokens = preg_split('/[\s,.;]+/', strtolower($purposeText), -1, PREG_SPLIT_NO_EMPTY);

    // SQL-level weighted aggregation for performance
    $departmentScores = DB::table('prediction_keywords')
        ->join('departments', 'prediction_keywords.department_id', '=', 'departments.id')
        ->whereIn('prediction_keywords.keyword', $tokens)
        ->select('departments.name', DB::raw('SUM(prediction_keywords.weight) as score'))
        ->groupBy('departments.name')
        ->orderByDesc('score')
        ->get();

    return $departmentScores->pluck('name')->toArray() ?: ['Records'];
}
```

### The Learning Loop
When a Records Officer manually overrides a suggested route for a non-official purpose, the `UpdateKeywordWeights` job is dispatched to adjust the weights, improving future accuracy.

---

## 4. Document Hashing & Integrity (The Trust Builder)

The "Trust Builder" guarantees the immutability of document records using cryptographic Merkle-chaining.

### Implementation: The Cryptographic Chain
Hashing is embedded in the `DocumentLog` model's `boot()` method to ensure it cannot be bypassed by application code.

```php
// app/Models/DocumentLog.php
protected static function boot() {
    static::creating(function ($log) {
        $lastLog = self::where('document_id', $log->document_id)->latest('id')->first();
        $log->previous_hash = $lastLog ? $lastLog->hash : 'genesis_hash';
        
        // Non-Repudiation: Digital Signature binding
        $log->signature = auth()->user()?->public_key ?? 'signed_by_guest';
        
        // State Hashing: Snapshot of document metadata at this moment
        $log->document_state_hash = self::calculateStateHash($log->document);

        // Chain Hashing: Previous Hash + Current Data + Signature
        $dataToHash = $log->document_id . $log->action . $log->created_at->toIso8601String() . 
                      $log->previous_hash . $log->document_state_hash . $log->signature;
        
        $log->hash = hash('sha256', $dataToHash);
    });
}
```

---

## 5. Resilience & Fallback Strategies

Designed to ensure the DTS remains stable under heavy load or edge-case scenarios.

### Memory-Safe PDF Chunking
To prevent RAM exhaustion when generating reports with 10,000+ documents, the system uses a chunking and merging strategy.

```php
// app/Jobs/GenerateReportJob.php
$query->chunk(250, function ($documents) use ($merger) {
    // Generate a standalone PDF for this chunk
    $pdf = Pdf::loadView('officer.report-pdf', ['releasedDocuments' => $documents]);
    
    // Save to disk to free up RAM immediately
    $tempPath = tempnam(sys_get_temp_dir(), 'pdf_chunk_');
    file_put_contents($tempPath, $pdf->output());
    $merger->addFile($tempPath);
    
    gc_collect_cycles(); // Force memory cleanup
});
```

### Other Safeguards
- **Queue Persistence**: Workers are wrapped in a shell loop that automatically restarts after a crash.
- **AI Defaults**: Defaults to the `Records Unit` if no keywords are matched, ensuring no "orphaned" documents.
- **Integrity Repair**: Authorized admins can rebuild a broken chain starting from a specific point forward using `php artisan dts:rebuild-chain`.
