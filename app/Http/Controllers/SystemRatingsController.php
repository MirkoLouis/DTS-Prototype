<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemRatingsController extends Controller
{
    /**
     * Display a dashboard of client ratings and feedback.
     */
    public function index()
    {
        $stats = Document::query()
            ->select(
                DB::raw('COUNT(rating) as total_ratings'),
                DB::raw('AVG(rating) as average_rating'),
                DB::raw('SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star'),
                DB::raw('SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star'),
                DB::raw('SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star'),
                DB::raw('SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star'),
                DB::raw('SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star')
            )
            ->whereNotNull('rating')
            ->first();

        $documents = Document::with('purpose')
            ->whereNotNull('rating')
            ->latest('updated_at') // Sort by when they were completed/rated
            ->paginate(15);

        return view('system.ratings', [
            'stats' => $stats,
            'documents' => $documents,
        ]);
    }
}
