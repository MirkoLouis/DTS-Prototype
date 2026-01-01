<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Jobs\UpdateKeywordWeights;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DocumentController extends Controller
{
    /**
     * Show the form for managing a document's route.
     *
     * @param  \App\Models\Document  $document
     * @return \Illuminate\View\View
     */
    public function manage(Document $document)
    {
        // Eager load the purpose to get the suggested_route
        $document->load('purpose');
        $departments = Department::all();

        return view('documents.manage', [
            'document' => $document,
            'departments' => $departments,
        ]);
    }

    /**
     * Finalize the document's route and put it into processing.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Document  $document
     * @return \Illuminate\Http\RedirectResponse
     */
    public function finalize(Request $request, Document $document)
    {
        $request->validate([
            'final_route' => 'required|json',
        ]);

        $finalizedRoute = json_decode($request->final_route);

        // Update the document
        $document->update([
            'status' => 'processing',
            'finalized_route' => $finalizedRoute,
            'current_step' => 1, // Set the current step to the first step in the route
        ]);

        // "Learn" from the officer's changes
        $purpose = $document->purpose;
        if ($purpose->suggested_route !== $finalizedRoute) {
            // If the purpose is not official, dispatch a job to learn from the changes.
            if (!$purpose->is_official) {
                UpdateKeywordWeights::dispatch($purpose->name, $finalizedRoute);
            }
            // Update the purpose's suggested_route for immediate use
            $purpose->update(['suggested_route' => $finalizedRoute]);
        }

        // Create the initial document log
        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => Auth::id(),
            'action' => 'Accepted and route finalized.',
            'hash' => '', // This will be set by the observer
            'previous_hash' => '', // This will be set by the observer
        ]);

        return redirect()->route('intake')->with('success', 'Document accepted and route has been finalized!');
    }

    /**
     * Decline and delete a pending document.
     *
     * @param  \App\Models\Document  $document
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(Document $document)
    {
        // Ensure only pending documents can be deleted
        if ($document->status !== 'pending') {
            return back()->with('error', 'This document cannot be declined as it is already being processed.');
        }

        $document->delete();

        return redirect()->route('intake')->with('success', 'Success! The document has been declined and removed from the system.');
    }

    /**
     * Display the specified document's data and logs.
     *
     * @param  \App\Models\Document  $document
     * @return \Illuminate\View\View
     */
    public function show(Document $document)
    {
        $document->load(['purpose', 'logs.user']);
        return view('documents.show', ['document' => $document]);
    }

    /**
     * Freeze the specified document.
     *
     * @param  \App\Models\Document  $document
     * @return \Illuminate\Http\RedirectResponse
     */
    public function freeze(Document $document)
    {
        $document->status = 'frozen';
        $document->save();

        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => Auth::id(),
            'action' => 'ADMIN: Document frozen.',
            'remarks' => 'An administrator has frozen this document, likely pending an integrity investigation.',
        ]);

        return response()->json(['status' => 'success', 'message' => 'Document has been frozen successfully.']);
    }

    /**
     * Unfreeze the specified document.
     *
     * @param  \App\Models\Document  $document
     * @return \Illuminate\Http\JsonResponse
     */
    public function unfreeze(Document $document)
    {
        // Add logic to determine what the previous status was, or just revert to 'processing'.
        // For simplicity, we'll revert to 'processing'.
        $document->status = 'processing';
        $document->save();

        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => Auth::id(),
            'action' => 'ADMIN: Document unfrozen.',
            'remarks' => 'An administrator has unfrozen this document, allowing it to continue processing.',
        ]);

        return response()->json(['status' => 'success', 'message' => 'Document has been unfrozen successfully.']);
    }
}

        