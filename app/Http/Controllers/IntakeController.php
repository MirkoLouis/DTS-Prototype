<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentLog;
use App\Models\Purpose;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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
        $filterStatus = $request->input('status');
        $filterPurpose = $request->input('purpose');
        $filterSubmitter = $request->input('submitter');
        $filterDate = $request->input('date_handled');

        $logsQuery = DocumentLog::where('user_id', $officerId)
            ->where('action', 'Accepted and Document Routing finalized')
            ->whereHas('document', function ($query) use ($searchTerm, $filterStatus, $filterPurpose, $filterSubmitter) {
                if ($searchTerm) {
                    $query->where('tracking_code', 'like', '%' . $searchTerm . '%')
                          ->orWhere('guest_info->name', 'like', '%' . $searchTerm . '%')
                          ->orWhereHas('purpose', function ($subQuery) use ($searchTerm) {
                              $subQuery->where('name', 'like', '%' . $searchTerm . '%');
                          });
                }
                
                if ($filterStatus && $filterStatus !== 'all') {
                    $query->where('status', $filterStatus);
                }

                if ($filterPurpose && $filterPurpose !== 'all') {
                    $query->whereHas('purpose', function ($subQuery) use ($filterPurpose) {
                        $subQuery->where('name', $filterPurpose);
                    });
                }
                
                if ($filterSubmitter && $filterSubmitter !== 'all') {
                    $query->where('guest_info->name', $filterSubmitter);
                }
            })
            ->with(['document.purpose'])
            ->when($filterDate, function ($query) use ($filterDate) {
                $query->whereDate('created_at', $filterDate);
            })
            ->latest();

        $handledLogs = $logsQuery->paginate(10)->withQueryString();
        
        // Data for filters
        $purposes = Purpose::orderBy('name')->get();
        $statuses = ['pending', 'processing', 'completed', 'frozen', 'declined']; // All possible statuses
        $submitters = Document::select(DB::raw('JSON_UNQUOTE(guest_info->"$.name") as name'))
                              ->distinct()
                              ->orderBy('name')
                              ->get()
                              ->pluck('name');

        if ($request->ajax()) {
            return view('general.partials.intake-table', ['handledLogs' => $handledLogs])->render();
        }

        return view('officer.intake', [
            'handledLogs' => $handledLogs, 
            'purposes' => $purposes,
            'statuses' => $statuses,
            'submitters' => $submitters,
        ]);
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
            'tracking_code' => 'required|string|exists:documents,tracking_code',
        ]);

        $document = Document::where('tracking_code', $request->tracking_code)->firstOrFail();

        // Use a switch statement for clarity, mirroring DocumentController@scan
        switch ($document->status) {
            case 'pending':
                // This is the only successful case for intake, redirect to the manage page.
                return redirect()->route('documents.manage', $document);

            case 'processing':
            case 'in_transit':
                // Active documents are "already intaked".
                return redirect()->route('intake')->with('info', 'This document is already in process and cannot be intaked again.');

            case 'ready_for_release':
            case 'completed':
            case 'declined':
                // For terminal statuses, display a message and stay on the intake page.
                return redirect()->route('intake')->with('info', 'This document has already been released, please check your tracking code again.');

            default:
                // Fallback for any other status (like 'frozen')
                return redirect()->route('intake')->with('error', 'This document cannot be processed at this time. Its current status is: ' . ucfirst($document->status));
        }
    }
}