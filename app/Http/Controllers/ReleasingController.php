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
        $searchTerm = $request->input('search');
        $filterPurpose = $request->input('purpose');
        $filterSubmitter = $request->input('submitter');
        $filterDate = $request->input('date');

        $query = Document::where('status', 'ready_for_release');

        if ($searchTerm) {
            $query->where(function ($q) use ($searchTerm) {
                $q->where('tracking_code', 'like', '%' . $searchTerm . '%')
                  ->orWhere('title', 'like', '%' . $searchTerm . '%');
            });
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

        $documents = $query->with('purpose')->latest()->paginate(10)->withQueryString();

        if ($request->ajax()) {
            return view('general.partials.releasing-table', ['documents' => $documents]);
        }

        $purposes = \App\Models\Purpose::orderBy('name')->get();

        return view('officer.releasing.index', [
            'documents' => $documents,
            'purposes' => $purposes,
        ]);
    }

    /**
     * Receive a document that has finished its route and add it to the releasing queue.
     * Stricter version of the scan action, specifically for releasing, now with cryptographic signing.
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
        $user = Auth::user();

        // VALIDATION
        if ($document->status !== 'in_transit') {
            return redirect()->route('releasing')->with('error', 'This document is not in a receivable state.');
        }

        $route = $document->finalized_route;
        if (($document->current_step - 1) < count($route)) {
            return redirect()->route('releasing')->with('error', 'This document has not completed all intermediate processing steps.');
        }

        $document->update(['status' => 'ready_for_release']);

        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'action' => 'Ready for Releasing',
            'remarks' => 'All processing steps completed. Document received by Records Unit for final releasing.',
        ]);

        return redirect()->route('releasing')->with('success', "Document {$document->tracking_code} is now ready for releasing.");
    }

    /**
     * Mark the specified document as completed.
     */
    public function complete(Request $request, Document $document)
    {
        if ($document->status !== 'ready_for_release') {
            return redirect()->route('releasing')->with('error', 'This document is not ready for release.');
        }

        $request->validate([
            'pin' => ['required', 'string'],
        ]);

        $user = Auth::user();
        $action = 'Document Released';
        $remarks = 'The document has been released to the client.';

        // Digital Signature
        $dataToSign = $document->tracking_code . '|' . $action . '|' . $remarks . '|' . now()->toIso8601String();
        $signature = $user->sign($request->pin, $dataToSign);

        if ($signature === false) {
            return back()->with('error', 'Invalid Security PIN. Transaction aborted.');
        }

        if ($signature === null) {
            return back()->with('error', 'Your digital signature has not been initialized.');
        }

        $document->update(['status' => 'completed']);

        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'action' => $action,
            'remarks' => $remarks,
            'signature' => $signature,
        ]);

        return redirect()->route('releasing')->with('success', 'Document marked as completed and released.');
    }
}
