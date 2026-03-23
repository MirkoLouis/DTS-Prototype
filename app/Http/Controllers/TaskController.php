<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Services\MetricUpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    protected $metrics;

    public function __construct(MetricUpdateService $metrics)
    {
        $this->metrics = $metrics;
    }

    /**
     * Display the tasks page for Staff, showing documents assigned to their department.
     */
    public function index(Request $request)
    {
        $user = Auth::user()->load('department');
        $userDepartment = $user->department;

        $searchTerm = $request->input('search');
        $filterStatus = $request->input('status');
        $filterPurpose = $request->input('purpose');
        $filterSubmitter = $request->input('submitter');
        $filterDate = $request->input('date');

        if (!$userDepartment) {
            $documentsForUser = collect();
        } else {
            // OPTIMIZATION: Use the indexed current_department_id instead of JSON extraction
            $query = Document::with('purpose')
                ->where('status', 'processing')
                ->where('current_department_id', $userDepartment->id);

            // Apply Filters
            if ($searchTerm) {
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('tracking_code', 'like', '%' . $searchTerm . '%')
                      ->orWhere('title', 'like', '%' . $searchTerm . '%')
                      ->orWhereHas('purpose', function ($subQuery) use ($searchTerm) {
                          $subQuery->where('name', 'like', '%' . $searchTerm . '%');
                      });
                });
            }

            if ($filterStatus && $filterStatus !== 'all') {
                $query->where('status', $filterStatus);
            }

            if ($filterPurpose && $filterPurpose !== 'all') {
                $query->whereHas('purpose', function ($subQuery) use ($filterPurpose) {
                    $subQuery->where('name', $filterPurpose);
                });
            }

            if ($filterSubmitter) {
                $query->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(guest_info, "$.name"))) LIKE ?', ['%' . strtolower($filterSubmitter) . '%']);
            }

            if ($filterDate) {
                $query->whereDate('created_at', $filterDate);
            }

            $documentsForUser = $query->latest()->paginate(15)->withQueryString();
        }

        if ($request->ajax()) {
            return view('general.partials.tasks-list', ['documents' => $documentsForUser]);
        }

        $viewName = 'staff.tasks'; // Default for staff
        if (Auth::user()->role === 'officer') {
            $viewName = 'officer.officer-tasks'; // New view for officers
        }

        $purposes = \App\Models\Purpose::orderBy('name')->get();
        $statuses = ['processing']; // Only processing for this view

        return view($viewName, [
            'documents' => $documentsForUser,
            'departmentName' => $userDepartment ? $userDepartment->name : 'Your', // Provide a fallback
            'purposes' => $purposes,
            'statuses' => $statuses,
        ]);
    }

    /**
     * Mark the current step for a document as complete and advance it.
     */
    public function complete(Request $request, Document $document)
    {
        \Illuminate\Support\Facades\Gate::authorize('process', $document);

        $request->validate([
            'pin' => 'required|string',
        ]);

        $user = Auth::user()->load('department');
        $userDepartment = $user->department;

        // Calculate processing time from the last 'Received' log for this department
        $lastReceivedLog = $document->logs()
            ->where('action', 'Received')
            ->where('user_id', $user->id)
            ->latest()
            ->first();
        
        $secondsTaken = $lastReceivedLog ? now()->diffInSeconds($lastReceivedLog->created_at) : 0;

        // Advance the step and set status to 'in_transit' BEFORE signing
        $totalSteps = count($document->finalized_route);
        $document->current_step += 1;
        $document->status = 'in_transit';

        // Update current_department_id to the NEXT department (if any)
        $nextDepartmentId = null;
        if ($document->current_step <= $totalSteps) {
            $nextDeptName = $document->finalized_route[$document->current_step - 1]['name'];
            $nextDept = Department::where('name', $nextDeptName)->first();
            $nextDepartmentId = $nextDept ? $nextDept->id : null;
        } else {
            // If it was the last processing step, it's heading to Records Unit for release
            $recordsUnit = Department::where('name', 'Records Unit')->first();
            $nextDepartmentId = $recordsUnit ? $recordsUnit->id : null;
        }
        
        $originalDepartmentId = $document->current_department_id;
        $document->current_department_id = $nextDepartmentId;

        if ($document->current_step > $totalSteps) {
            $document->status = 'ready_for_release';
        } else {
            $document->status = 'in_transit';
        }

        $document->save();

        // Update Metrics for the COMPLETING department
        $this->metrics->incrementProcessed($userDepartment->id, $secondsTaken);

        $action = 'Processing Complete';
        
        // Determine the remarks for the log
        if ($document->current_step > $totalSteps) {
            $remarks = "Final step processed by {$userDepartment->name}. In transit to Records Unit for releasing.";
        } else {
            $nextDepartmentName = $document->finalized_route[$document->current_step - 1]['name'];
            $remarks = "Step processed by {$userDepartment->name}. In transit to {$nextDepartmentName}.";
        }

        // 1. Generate Cryptographic Signature (Bonded to the NOW UPDATED document state)
        $stateHash = DocumentLog::calculateStateHash($document);
        $signature = $user->sign($request->pin, $action, $stateHash);

        if ($signature === false) {
            // Revert state if signing fails
            $document->current_step -= 1;
            $document->status = 'processing';
            $document->current_department_id = $originalDepartmentId;
            $document->save();
            return back()->with('error', 'Invalid Security PIN. Transaction aborted.');
        }

        if ($signature === null) {
            // Revert state if no signature found
            $document->current_step -= 1;
            $document->status = 'processing';
            $document->current_department_id = $originalDepartmentId;
            $document->save();
            return back()->with('error', 'Your digital signature has not been initialized. Please refresh the page.');
        }

        // Create a log entry for this action, including the Ed25519 signature
        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'action' => $action,
            'remarks' => $remarks,
            'signature' => $signature, // This is now a real cryptographic signature
            'document_state_hash' => $stateHash, // Explicitly pass the hash used for signing
        ]);

        // Determine the redirect route based on user role
        $userRole = Auth::user()->role;
        $redirectRoute = ($userRole === 'officer') ? 'officer.tasks' : 'staff.tasks';

        return redirect()->route($redirectRoute)->with('success', 'Step completed. Document is now in transit.');
    }
    /**
     * Display a list of documents previously completed by the user.
     */
    public function completed(Request $request)
    {
        $userId = Auth::id();
        $searchTerm = $request->input('search');
        $filterStatus = $request->input('status');
        $filterPurpose = $request->input('purpose');
        $filterSubmitter = $request->input('submitter');
        $filterDate = $request->input('date');

        $query = Document::whereHas('logs', function ($q) use ($userId) {
            $q->where('user_id', $userId)
              ->where('action', 'Processing Complete');
        })->with('purpose');

        // Apply Filters
        if ($searchTerm) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('tracking_code', 'like', '%' . $searchTerm . '%')
                  ->orWhere('title', 'like', '%' . $searchTerm . '%')
                  ->orWhereHas('purpose', function ($subQuery) use ($searchTerm) {
                      $subQuery->where('name', 'like', '%' . $searchTerm . '%');
                  });
            });
        }

        if ($filterStatus && $filterStatus !== 'all') {
            $query->where('status', $filterStatus);
        }

        if ($filterPurpose && $filterPurpose !== 'all') {
            $query->whereHas('purpose', function ($subQuery) use ($filterPurpose) {
                $subQuery->where('name', $filterPurpose);
            });
        }

        if ($filterSubmitter) {
            $query->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(guest_info, "$.name"))) LIKE ?', ['%' . strtolower($filterSubmitter) . '%']);
        }

        if ($filterDate) {
            $query->whereDate('created_at', $filterDate);
        }

        $documents = $query->latest()->paginate(15)->withQueryString();

        if ($request->ajax()) {
            return view('general.partials.completed-tasks-list', ['documents' => $documents]);
        }

        $purposes = \App\Models\Purpose::orderBy('name')->get();
        $statuses = ['processing', 'in_transit', 'ready_for_release', 'completed', 'declined'];

        return view('staff.tasks-completed', [
            'documents' => $documents,
            'purposes' => $purposes,
            'statuses' => $statuses,
        ]);
    }
}
