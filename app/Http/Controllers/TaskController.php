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
    public function index()
    {
        // Get the authenticated user and load their department relationship
        $user = Auth::user()->load('department');
        $userDepartment = $user->department;

        // If user is not in a department, they have no tasks.
        if (!$userDepartment) {
            return view('tasks', ['documents' => collect()]);
        }

        // Get all documents that are currently in 'processing' status
        $processingDocuments = Document::with('purpose')
                                       ->where('status', 'processing')
                                       ->latest()
                                       ->get();

        // Filter the documents to find only those where the current step matches the user's department
        $documentsForUser = $processingDocuments->filter(function ($document) use ($userDepartment) {
            // A document must have a route and a current step to be assigned
            if (empty($document->finalized_route) || is_null($document->current_step)) {
                return false;
            }

            // The current_step is 1-based, array indices are 0-based
            $currentStepIndex = $document->current_step - 1;

            // Check if the current step is valid for the route array
            if (isset($document->finalized_route[$currentStepIndex])) {
                // Return true if the department name at the current route step matches the user's department name
                return $document->finalized_route[$currentStepIndex] === $userDepartment->name;
            }

            return false;
        });

        return view('tasks', ['documents' => $documentsForUser]);
    }

    /**
     * Mark the current step for a document as complete and advance it.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Document  $document
     * @return \Illuminate\Http\RedirectResponse
     */
    public function complete(Request $request, Document $document)
    {
        $user = Auth::user()->load('department');
        $userDepartment = $user->department;

        // Authorization: Check if the document is actually assigned to this user's department
        $currentStepIndex = $document->current_step - 1;
        $currentDepartmentOnRoute = $document->finalized_route[$currentStepIndex] ?? null;

        if (!$userDepartment || $currentDepartmentOnRoute !== $userDepartment->name) {
            return back()->with('error', 'You are not authorized to perform this action on this document.');
        }

        // Advance the step
        $document->current_step += 1;

        // Check if the document's route is now complete
        if ($document->current_step > count($document->finalized_route)) {
            $document->status = 'completed';
            $action = 'Processing complete. Document is now ' . $document->status . '.';
        } else {
            $nextDepartment = $document->finalized_route[$document->current_step - 1];
            $action = 'Step completed. Document forwarded to ' . $nextDepartment . '.';
        }

        $document->save();

        // Create a log entry for this action
        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'action' => $action,
            'remarks' => 'Step processed by ' . $userDepartment->name . ' department.',
        ]);

        return redirect()->route('tasks')->with('success', 'Document step completed successfully!');
    }
}
