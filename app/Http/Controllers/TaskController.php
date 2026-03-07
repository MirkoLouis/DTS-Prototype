<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
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
            $query = Document::with('purpose')
                ->where('status', 'processing')
                ->where(function ($q) use ($userDepartment) {
                    $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(finalized_route, CONCAT('$[', current_step - 1, '].name'))) = ?", [$userDepartment->name]);
                });

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

        $totalSteps = count($document->finalized_route);
        $action = 'Processing Complete';
        
        // Determine the remarks for the log
        if ($document->current_step > $totalSteps) {
            $remarks = "Final step processed by {$userDepartment->name}. In transit to Records Unit for releasing.";
        } else {
            $nextDepartmentName = $document->finalized_route[$document->current_step - 1]['name'];
            $remarks = "Step processed by {$userDepartment->name}. In transit to {$nextDepartmentName}.";
        }

        // 1. Generate Cryptographic Signature (Bonded to current document state)
        $stateHash = DocumentLog::calculateStateHash($document);
        $signature = $user->sign($request->pin, $action, $stateHash);

        if ($signature === false) {
            return back()->with('error', 'Invalid Security PIN. Transaction aborted.');
        }

        if ($signature === null) {
            return back()->with('error', 'Your digital signature has not been initialized. Please refresh the page.');
        }

        // Advance the step and set status to 'in_transit'
        $document->current_step += 1;
        $document->status = 'in_transit';
        $document->save();

        // Create a log entry for this action, including the Ed25519 signature
        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'action' => $action,
            'remarks' => $remarks,
            'signature' => $signature, // This is now a real cryptographic signature
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
