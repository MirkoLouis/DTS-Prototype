<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Jobs\UpdateKeywordWeights;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

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
     * Decline a pending document.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Document  $document
     * @return \Illuminate\Http\RedirectResponse
     */
    public function decline(Request $request, Document $document)
    {
        // Ensure only pending documents can be declined
        if ($document->status !== 'pending') {
            return back()->with('error', 'This document cannot be declined as it is already being processed.');
        }

        $request->validate([
            'decline_reason' => 'required|string|max:1000',
        ]);

        $document->update([
            'status' => 'declined',
            'decline_reason' => $request->decline_reason,
            'declined_at' => now(),
        ]);

        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => Auth::id(),
            'action' => 'Document Declined',
            'remarks' => $request->decline_reason,
        ]);

        return redirect()->route('intake')->with('success', 'The document has been successfully declined.');
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

    /**
     * Store a rating for the specified document.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Document  $document
     * @return \Illuminate\Http\JsonResponse
     */
    public function rate(Request $request, Document $document)
    {
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
        ]);

        if ($document->status !== 'completed') {
            return response()->json(['message' => 'This document cannot be rated yet.'], 422);
        }

        if ($document->rating !== null) {
            return response()->json(['message' => 'This document has already been rated.'], 422);
        }

        $document->rating = $validated['rating'];
        $document->save();

        return response()->json(['status' => 'success', 'message' => 'Thank you for your feedback!']);
    }

    /**
     * Generate a printable PDF tracking form for the specified document.
     *
     * @param  \App\Models\Document  $document
     * @return \Illuminate\Http\Response
     */
    public function printTrackingForm(Document $document)
    {
        // Eager load relationships to prevent N+1 issues in the view
        $document->load('purpose');

        $qrCode = base64_encode(QrCode::format('png')->size(110)->generate($document->tracking_code));

        $pdf = Pdf::loadView('documents.tracking-form-pdf', [
            'document' => $document,
            'qrCode' => $qrCode,
        ]);

        return $pdf->setPaper('a4')->stream('document-tracking-form-'.$document->tracking_code.'.pdf');
    }
}

        