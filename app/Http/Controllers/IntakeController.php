<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IntakeController extends Controller
{
    /**
     * Display the intake page for Records Officers.
     * This page shows the lookup form and a list of recently handled documents.
     * This method also handles AJAX requests for searching/pagination.
     */
    public function index(Request $request)
    {
        $officerId = Auth::id();
        $searchTerm = $request->input('search');

        $logsQuery = DocumentLog::where('user_id', $officerId)
            ->where('action', 'Accepted and route finalized.')
            ->whereHas('document', function ($query) use ($searchTerm) {
                if ($searchTerm) {
                    $query->where('tracking_code', 'like', '%' . $searchTerm . '%')
                          ->orWhere('guest_info->name', 'like', '%' . $searchTerm . '%')
                          ->orWhereHas('purpose', function ($subQuery) use ($searchTerm) {
                              $subQuery->where('name', 'like', '%' . $searchTerm . '%');
                          });
                }
            })
            ->with(['document.purpose']) // Eager load the document and its purpose
            ->latest();

        $handledLogs = $logsQuery->paginate(10)->withQueryString();

        if ($request->ajax()) {
            return view('partials.intake-table', ['handledLogs' => $handledLogs])->render();
        }

        return view('intake', ['handledLogs' => $handledLogs]);
    }

    /**
     * Find a document by its tracking code and redirect to the manage page.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function find(Request $request)
    {
        $request->validate([
            'tracking_code' => 'required|string',
        ]);

        $document = Document::where('tracking_code', $request->tracking_code)->first();

        if (!$document) {
            return redirect()->route('intake')->with('error', 'No document found with that tracking code.');
        }

        if ($document->status !== 'pending') {
            return redirect()->route('intake')->with('error', 'This document has already been processed and is no longer pending intake.');
        }

        return redirect()->route('documents.manage', $document);
    }
}