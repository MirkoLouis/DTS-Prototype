<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Document;
use App\Models\DocumentLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ReturnRequestController extends Controller
{
    /**
     * Display the form for requesting a document return.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('staff.return-requests.index');
    }

    /**
     * Store a new return request, which injects the user's department
     * into the document's route.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'tracking_code' => ['required', 'string', 'exists:documents,tracking_code'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $user = Auth::user()->load('department');
        $requestingDepartment = $user->department;

        if (!$requestingDepartment) {
            return back()->with('error', 'You must belong to a department to make this request.')->withInput();
        }

        $document = Document::where('tracking_code', $request->tracking_code)->firstOrFail();

        // 1. Validate document status
        switch ($document->status) {
            case 'processing':
            case 'in_transit':
                // Valid statuses, break to continue to the main logic.
                break;

            case 'ready_for_release':
                // Special case: Document is in the release queue. It can be pulled back into the workflow.
                $currentRoute = $document->finalized_route ?? [];

                // 1. Modify the route: Add the requesting department to the end of the processing route.
                $newRoute = $currentRoute;
                $newRoute[] = ['name' => $requestingDepartment->name, 'type' => 'returned'];

                // 2. IMPORTANT: Set the current step to the index of this new, last step.
                $newStep = count($newRoute);

                // 3. Update the document
                $document->finalized_route = $newRoute;
                $document->status = 'in_transit'; // Put the document back in transit to the new step.
                $document->current_step = $newStep; // Set the pointer to the new, correct step.
                $document->save();

                // 4. Log the action
                DocumentLog::create([
                    'document_id' => $document->id,
                    'user_id' => $user->id,
                    'action' => 'Returned from Releasing',
                    'remarks' => "{$requestingDepartment->name} requested the document back from the releasing queue. Reason: " . $request->input('reason'),
                ]);

                return redirect()->route('return-requests.index')->with('success', "Success! Document {$document->tracking_code} has been pulled back from releasing and is now in transit to {$requestingDepartment->name}.");

            case 'completed':
            case 'declined':
                return back()->with('info', 'This document has already been released, please check your tracking code again.')->withInput();

            case 'pending':
                return back()->with('error', 'This document is still pending intake and does not have a route to modify.')->withInput();

            default: // Catches 'frozen' and any other statuses
                return back()->with('error', 'This document cannot be rerouted at this time. Its current status is: ' . ucfirst($document->status))->withInput();
        }

        $currentRoute = $document->finalized_route;
        $currentStep = $document->current_step;
        $currentStepIndex = $currentStep - 1;

        // 2. Prevent rerouting if the requesting department is ALREADY the current or next in line
        $departmentCurrentlyProcessing = $currentRoute[$currentStepIndex]['name'] ?? null;
        if ($departmentCurrentlyProcessing === $requestingDepartment->name) {
            return back()->with('error', 'This document is already assigned to your department for processing.')->withInput();
        }
        
        $nextDepartmentInLine = $currentRoute[$currentStep]['name'] ?? null;
        if ($nextDepartmentInLine === $requestingDepartment->name) {
             return back()->with('error', 'Your department is already the next step in this document\'s route.')->withInput();
        }

        // 3. Modify the route
        $newRoute = $currentRoute;
        // Inject the requesting department's data immediately after the current step
        $newStepData = ['name' => $requestingDepartment->name, 'type' => 'returned'];
        array_splice($newRoute, $currentStep, 0, [$newStepData]);

        // 4. Update the document
        $document->finalized_route = $newRoute;
        $document->save();

        // 5. Log the action
        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'action' => 'Rerouted',
            'remarks' => "{$requestingDepartment->name} requested the document for correction and was added to the route. Reason: " . $request->input('reason'),
        ]);

        return redirect()->route('return-requests.index')->with('success', "Success! The route for {$document->tracking_code} has been updated. {$requestingDepartment->name} is now the next step.");
    }
}
