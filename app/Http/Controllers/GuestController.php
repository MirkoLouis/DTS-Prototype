<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Purpose;
use App\Services\RoutePredictionService; // Import the service
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GuestController extends Controller
{
    /**
     * Show the main welcome page with the document submission form.
     */
    public function welcome()
    {
        // Only show official purposes in the dropdown
        $purposes = Purpose::where('is_official', true)->get();

        return view('welcome', ['purposes' => $purposes]);
    }

    /**
     * Store a new document request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Services\RoutePredictionService  $routePredictionService // Inject the service
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, RoutePredictionService $routePredictionService)
    {
        $rules = [
            'guest_name' => 'required|string|max:255',
            'guest_email' => 'required|email|max:255',
            'purpose_id' => 'required|integer',
        ];

        // If 'Other' purpose is selected
        if ($request->input('purpose_id') == 0) {
            $rules['other_purpose_text'] = 'required|string|max:255'; // Removed unique rule
        } else {
            // Validate that the purpose_id actually exists in the database
            $rules['purpose_id'] .= '|exists:purposes,id';
        }

        $request->validate($rules);

        $finalPurposeId = $request->input('purpose_id');
        $suggestedRoute = [];

        // Handle "Other" purpose
        if ($finalPurposeId == 0) {
            $otherPurposeText = $request->input('other_purpose_text');
            // Check if a similar non-official purpose already exists to prevent duplicates
            $existingPurpose = Purpose::where('name', $otherPurposeText)->where('is_official', false)->first();

            if ($existingPurpose) {
                $finalPurposeId = $existingPurpose->id;
            } else {
                $suggestedRoute = $routePredictionService->predict($otherPurposeText);
                $newPurpose = Purpose::create([
                    'name' => $otherPurposeText,
                    'is_official' => false,
                    'requirements' => [],
                    'suggested_route' => $suggestedRoute,
                ]);
                $finalPurposeId = $newPurpose->id;
            }
        }
        
        // New Tracking Code Algorithm
        $dataForHash = time() . $request->input('guest_name') . $request->input('guest_email');
        $trackingCode = 'DEPED-' . strtoupper(substr(sha1($dataForHash), 0, 10));


        $document = Document::create([
            'tracking_code' => $trackingCode,
            'guest_info' => [
                'name' => $request->input('guest_name'),
                'email' => $request->input('guest_email'),
            ],
            'purpose_id' => $finalPurposeId,
            'status' => 'pending',
        ]);

        return redirect()->route('success', ['tracking_code' => $document->tracking_code]);
    }

    /**
     * Show the success page with the tracking code.
     */
    public function success($tracking_code)
    {
        return view('success', ['tracking_code' => $tracking_code]);
    }

    /**
     * Show the public tracking page for multiple documents.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function track(Request $request)
    {
        $codesParam = $request->query('codes');
        $trackingCodes = [];

        if ($codesParam) {
            $trackingCodes = array_filter(explode(',', $codesParam));
        }

        if (empty($trackingCodes)) {
            // If no codes, redirect to welcome or show a specific message
            return redirect()->route('welcome')->with('info', 'Please enter a tracking code to view its status.');
        }

        $documents = Document::with('purpose')
                            ->whereIn('tracking_code', $trackingCodes)
                            ->get();

        return view('track', ['documents' => $documents]);
    }

    /**
     * Get a single document card for AJAX requests.
     *
     * @param string $tracking_code
     * @return \Illuminate\Http\Response
     */
    public function getTrackedDocumentModule($tracking_code)
    {
        $document = Document::with('purpose')->where('tracking_code', $tracking_code)->firstOrFail();

        // Render the component as a string
        $html = view('components.document-card', ['document' => $document])->render();

        return response($html, 200);
    }

    /**
     * Get lightweight status updates for multiple documents for AJAX polling.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStatusUpdates(Request $request)
    {
        $codesParam = $request->query('codes');
        if (!$codesParam) {
            return response()->json([]);
        }

        $trackingCodes = array_filter(explode(',', $codesParam));

        $statuses = Document::whereIn('tracking_code', $trackingCodes)
                            ->select('tracking_code', 'status', 'current_step')
                            ->get();

        return response()->json($statuses);
    }
}
