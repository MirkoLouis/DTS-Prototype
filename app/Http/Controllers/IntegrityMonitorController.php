<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;

class IntegrityMonitorController extends Controller
{
    /**
     * Display the integrity monitor page for Admins, showing all documents.
     */
    public function index(Request $request)
    {
        $searchTerm = $request->input('search');
        $filterStatus = $request->input('status');
        $filterPurpose = $request->input('purpose');
        $filterSubmitter = $request->input('submitter');
        $filterDate = $request->input('date');

        $documentsQuery = Document::with('purpose')
            ->where(function ($query) use ($searchTerm) {
                if ($searchTerm) {
                    $query->where('tracking_code', 'like', '%' . $searchTerm . '%')
                          ->orWhere('title', 'like', '%' . $searchTerm . '%')
                          ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(guest_info, "$.name"))) LIKE ?', ['%' . strtolower($searchTerm) . '%'])
                          ->orWhere('status', 'like', '%' . $searchTerm . '%')
                          ->orWhereHas('purpose', function ($subQuery) use ($searchTerm) {
                              $subQuery->where('name', 'like', '%' . $searchTerm . '%');
                          });
                }
            })
            ->when($filterStatus && $filterStatus !== 'all', function ($query) use ($filterStatus) {
                $query->where('status', $filterStatus);
            })
            ->when($filterPurpose && $filterPurpose !== 'all', function ($query) use ($filterPurpose) {
                $query->whereHas('purpose', function ($subQuery) use ($filterPurpose) {
                    $subQuery->where('name', $filterPurpose);
                });
            })
            ->when($filterSubmitter, function ($query) use ($filterSubmitter) {
                $query->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(guest_info, "$.name"))) LIKE ?', ['%' . strtolower($filterSubmitter) . '%']);
            })
            ->when($filterDate, function ($query) use ($filterDate) {
                $query->whereDate('created_at', $filterDate);
            })
            ->latest();

        $documents = $documentsQuery->paginate(15)->withQueryString();

        // Data for filters
        $purposes = \App\Models\Purpose::orderBy('name')->get();
        $statuses = ['pending', 'in_transit', 'processing', 'ready_for_release', 'completed', 'frozen', 'declined'];

        if ($request->ajax()) {
            return view('general.partials.document-list-table', ['documents' => $documents])->render();
        }

        return view('admin.integrity-monitor', [
            'documents' => $documents,
            'purposes' => $purposes,
            'statuses' => $statuses,
        ]);
    }
}