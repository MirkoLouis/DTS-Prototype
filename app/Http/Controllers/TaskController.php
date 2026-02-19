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
        $user = Auth::user()->load('department');
        $userDepartment = $user->department;

        // Authorization: Check if the document is actually assigned to this user's department
        $currentStepIndex = $document->current_step - 1;
        $currentDepartmentOnRoute = $document->finalized_route[$currentStepIndex]['name'] ?? null;

        // Authorization: Check if the user is authorized to perform this action on this document.
        // Get the department name for the current step in the document's route
        $currentStepInRouteName = $document->finalized_route[$currentStepIndex]['name'] ?? null;

        if ($user->role === 'officer') {
            if (!$userDepartment) {
                return back()->with('error', 'Your user account (Records Officer) is not assigned to a department, thus cannot complete this step.');
            }
            if ($document->status !== 'processing') {
                return back()->with('error', 'The document is not in a processing state.');
            }
            if ($currentStepInRouteName !== $userDepartment->name) {
                return back()->with('error', "As a Records Officer, you cannot complete this step. The document is currently at '{$currentStepInRouteName}' but your department is '{$userDepartment->name}'.");
            }
        }
        // Staff-specific authorization (or general authorization if not officer)
        else {
            if (!$userDepartment) {
                return back()->with('error', 'Your user account is not assigned to a department, thus cannot complete this step.');
            }
            if ($document->status !== 'processing') {
                return back()->with('error', 'The document is not in a processing state.');
            }
            if ($currentStepInRouteName !== $userDepartment->name) {
                return back()->with('error', "You are not authorized to complete this step. The document is currently at '{$currentStepInRouteName}' but your department is '{$userDepartment->name}'.");
            }
        }

        // Advance the step and set status to 'in_transit'
        $document->current_step += 1;
        $document->status = 'in_transit';

        $totalSteps = count($document->finalized_route);
        $action = 'Processing Complete';
        
        // Determine the remarks for the log
        if ($document->current_step > $totalSteps) {
            // This was the final internal processing step.
            $remarks = "Final step processed by {$userDepartment->name}. In transit to Records Unit for releasing.";
        } else {
            $nextDepartmentName = $document->finalized_route[$document->current_step - 1]['name'];
            $remarks = "Step processed by {$userDepartment->name}. In transit to {$nextDepartmentName}.";
        }

        $document->save();

        // Create a log entry for this action
        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'action' => $action,
            'remarks' => $remarks,
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
