<?php

namespace App\Http\Controllers;

use App\Models\DocumentLog;
use Illuminate\Http\Request;

class IntegrityMonitorController extends Controller
{
    /**
     * Display the integrity monitor page for Admins.
     */
    public function index()
    {
        $logs = DocumentLog::with(['document', 'user'])->latest()->get();
        return view('integrity-monitor', ['logs' => $logs]);
    }
}