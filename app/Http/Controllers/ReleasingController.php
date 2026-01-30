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