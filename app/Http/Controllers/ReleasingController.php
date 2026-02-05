<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReleasingController extends Controller
{
    /**
     * Display a listing of the documents ready for release.
     */
    public function index(Request $request)
    {
        // The new status 'ready_for_release' simplifies this query significantly.
        $documents = Document::where('status', 'ready_for_release')
                                ->latest()
                                ->paginate(10);

        if ($request->ajax()) {
            return view('partials.releasing-table', ['documents' => $documents]);
        }

        return view('releasing.index', [
            'documents' => $documents,
        ]);
    }

    /**
     * Receive a document that has finished its route and add it to the releasing queue.
     * This is a stricter version of the general scan action, specifically for the releasing workflow.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function receive(Request $request)
    {
        $request->validate([
            'tracking_code' => ['required', 'string', 'exists:documents,tracking_code'],
        ]);

        $document = Document::where('tracking_code', $request->tracking_code)->firstOrFail();
        $user = Auth::user(); // No need to load department, but useful for logging

        // STRICT VALIDATION:
        // 1. The document must be 'in_transit'.
        if ($document->status !== 'in_transit') {
            $statusMessage = 'This document cannot be received for releasing. Its current status is: ' . ucfirst($document->status);
            if (in_array($document->status, ['ready_for_release', 'completed'])) {
                $statusMessage = 'This document is already in the releasing process or has been completed.';
            }
            return redirect()->route('releasing')->with('error', $statusMessage);
        }

        // 2. The document must have completed all steps in its route.
        $route = $document->finalized_route;
        $currentStepIndex = $document->current_step - 1;

        if ($currentStepIndex < count($route)) {
            $nextDepartment = $route[$currentStepIndex]['name'] ?? 'an unknown department';
            return redirect()->route('releasing')->with('error', "This document has not completed its route. It is still in transit to {$nextDepartment}.");
        }

        // If validation passes, update the document status.
        $document->update(['status' => 'ready_for_release']);

        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'action' => 'Ready for Releasing',
            'remarks' => 'All processing steps completed. Document received by Records Unit for final releasing.',
        ]);

        return redirect()->route('releasing')->with('success', "Document {$document->tracking_code} is now ready for releasing and has been added to the queue.");
    }

    /**
     * Mark the specified document as completed.
     */
    public function complete(Request $request, Document $document)
    {
        // Ensure the document is actually ready for release before proceeding.
        if ($document->status !== 'ready_for_release') {
            return redirect()->route('releasing')->with('error', 'This document is not ready for release.');
        }

        // Update status to 'completed'
        $document->update(['status' => 'completed']);

        // Create the final log entry to mark the document as officially released.
        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => Auth::id(),
            'action' => 'Document Released',
            'remarks' => 'The document has been released to the client.',
        ]);

        return redirect()->route('releasing')->with('success', 'Document marked as completed and released.');
    }
}