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

        if (!$userDepartment) {
            $documentsForUser = collect();
        } else {
            $processingDocuments = Document::with('purpose')
                                        ->where('status', 'processing')
                                        ->latest()
                                        ->get();

            // Filter documents to find those where the current step matches the user's department
            $documentsForUser = $processingDocuments->filter(function ($document) use ($userDepartment) {
                if (empty($document->finalized_route) || is_null($document->current_step)) {
                    return false;
                }
                
                // The current step is 1-based, the array is 0-based
                $currentStepIndex = $document->current_step - 1;
                
                // Check if the current step is valid for the route
                if (isset($document->finalized_route[$currentStepIndex])) {
                    // Check if the department name at the current step matches the user's department name
                    return $document->finalized_route[$currentStepIndex] === $userDepartment->name;
                }

                return false;
            });
        }

        if ($request->ajax()) {
            return view('partials.tasks-list', ['documents' => $documentsForUser]);
        }

        return view('tasks', ['documents' => $documentsForUser]);
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
        $currentDepartmentOnRoute = $document->finalized_route[$currentStepIndex] ?? null;

        if (!$userDepartment || $currentDepartmentOnRoute !== $userDepartment->name) {
            return back()->with('error', 'You are not authorized to perform this action on this document.');
        }

        // Advance the step
        $document->current_step += 1;

        $totalSteps = count($document->finalized_route);

        // Check if the document's route is now complete
        if ($document->current_step > $totalSteps) {
            // This was the final internal processing step.
            // The status remains 'processing' so it can appear on the 'Releasing' page.
            $action = 'Final processing step completed. Document is now awaiting release.';
        } else {
            $nextDepartmentName = $document->finalized_route[$document->current_step - 1];
            $action = 'Step completed. Document forwarded to ' . $nextDepartmentName . '.';
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
