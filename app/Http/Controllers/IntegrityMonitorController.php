<?php

namespace App\Http\Controllers;

use App\Models\DocumentLog;
use Illuminate\Http\Request;

class IntegrityMonitorController extends Controller
{
    /**
     * Display the integrity monitor page for Admins.
     */
    public function index(Request $request)
    {
        $searchTerm = $request->input('search');

        $logsQuery = DocumentLog::with(['document', 'user'])
            ->where(function ($query) use ($searchTerm) {
                if ($searchTerm) {
                    $query->where('action', 'like', '%' . $searchTerm . '%')
                          ->orWhere('hash', 'like', '%' . $searchTerm . '%')
                          ->orWhere('previous_hash', 'like', '%' . $searchTerm . '%')
                          ->orWhereHas('document', function ($subQuery) use ($searchTerm) {
                              $subQuery->where('tracking_code', 'like', '%' . $searchTerm . '%');
                          })
                          ->orWhereHas('user', function ($subQuery) use ($searchTerm) {
                              $subQuery->where('name', 'like', '%' . $searchTerm . '%');
                          });
                }
            })
            ->latest();

        $logs = $logsQuery->paginate(10)->withQueryString();

        if ($request->ajax()) {
            return view('partials.integrity-log-table', ['logs' => $logs])->render();
        }

        return view('integrity-monitor', ['logs' => $logs]);
    }
}