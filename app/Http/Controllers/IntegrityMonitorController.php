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

        $documentsQuery = Document::with('purpose')
            ->where(function ($query) use ($searchTerm) {
                if ($searchTerm) {
                    $query->where('tracking_code', 'like', '%' . $searchTerm . '%')
                          ->orWhere('title', 'like', '%' . $searchTerm . '%')
                           ->orWhere('status', 'like', '%' . $searchTerm . '%')
                          ->orWhereHas('purpose', function ($subQuery) use ($searchTerm) {
                              $subQuery->where('name', 'like', '%' . $searchTerm . '%');
                          });
                }
            })
            ->latest();

        $documents = $documentsQuery->paginate(15)->withQueryString();

        if ($request->ajax()) {
            return view('general.partials.document-list-table', ['documents' => $documents])->render();
        }

        return view('admin.integrity-monitor', ['documents' => $documents]);
    }
}