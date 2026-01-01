<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Pagination\LengthAwarePaginator;

class ReleasingController extends Controller
{
    /**
     * Display a listing of the documents ready for release.
     */
    public function index()
    {
        // Get all documents that are in 'processing' status.
        $processingDocuments = Document::where('status', 'processing')->latest()->get();

        // Filter to find documents that have completed all internal steps.
        // A document is ready for release if its current_step is greater than the number of steps in its route.
        $readyForRelease = $processingDocuments->filter(function ($document) {
            if (empty($document->finalized_route)) {
                return false;
            }
            $totalSteps = count($document->finalized_route);
            return $document->current_step > $totalSteps;
        });

        // Manually paginate the filtered collection.
        $page = request()->get('page', 1);
        $perPage = 10;
        $paginatedResults = new LengthAwarePaginator(
            $readyForRelease->forPage($page, $perPage),
            $readyForRelease->count(),
            $perPage,
            $page,
            ['path' => request()->url()]
        );

        return view('releasing.index', [
            'documents' => $paginatedResults,
        ]);
    }

    /**
     * Mark the specified document as completed.
     */
    public function complete(Request $request, Document $document)
    {
        // Ensure the document is actually ready for release before proceeding.
        $totalSteps = count($document->finalized_route);

        if ($document->status !== 'processing' || $document->current_step <= $totalSteps) {
            return redirect()->route('releasing')->with('error', 'Document is not yet ready for release.');
        }

        // Update status to 'completed'
        $document->status = 'completed';
        $document->save();

        // Create the final log entry to mark the document as officially released.
        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => Auth::id(),
            'action' => 'Document Released',
            'description' => 'The document has been released to the client.',
        ]);

        return redirect()->route('releasing')->with('success', 'Document marked as completed and released.');
    }
}