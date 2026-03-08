<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Purpose;
use App\Models\Department;
use App\Services\RoutePredictionService; // Import the service
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class GuestController extends Controller
{
    /**
     * Show the main welcome page with the document submission form.
     */
    public function welcome()
    {
        // Only show official purposes in the dropdown
        $purposes = Purpose::where('is_official', true)->get();
        $departments = Department::orderBy('name')->get();

        return view('guest.welcome', [
            'purposes' => $purposes,
            'departments' => $departments
        ]);
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
            'guest_name' => 'required|string|max:255|regex:/^(?!.*@.*\..*)(?!.*\s+@).*$/', // Disallow email-like strings
            'guest_email' => 'nullable|email|max:255',
            'guest_phone' => 'nullable|string|max:255', // Add phone number validation
            'district' => 'required|string|max:255',
            'department' => 'required|string|max:255',
            'title' => 'required|string|max:255',
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
            $otherPurposeText = "Others: " . $request->input('other_purpose_text'); // Prepend "Others: "
            // Check if a similar non-official purpose already exists to prevent duplicates
            $existingPurpose = Purpose::where('name', $otherPurposeText)->where('is_official', false)->first();

            if ($existingPurpose) {
                $finalPurposeId = $existingPurpose->id;
            } else {
                $combinedContext = $request->input('title') . ' ' . $otherPurposeText;
                $suggestedRoute = $routePredictionService->predict(
                    $combinedContext, 
                    $request->input('department')
                );
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
            'title' => $request->input('title'),
            'guest_info' => [
                'name' => $request->input('guest_name'),
                'email' => $request->input('guest_email'),
                'phone' => $request->input('guest_phone'), // Add phone number to guest info
            ],
            'district' => $request->input('district'),
            'department' => $request->input('department'),
            'purpose_id' => $finalPurposeId,
            'status' => 'pending',
        ]);

        // Create the "genesis" log for the document's history
        \App\Models\DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => null, // Guest submission, no authenticated user
            'action' => 'Submitted',
            'remarks' => 'Document submitted by guest via the public portal.',
        ]);

        return redirect()->route('success', [
            'tracking_code' => $document->tracking_code,
            'document_id' => $document->id
        ]);
    }

    /**
     * Show the success page with the tracking code.
     */
    public function success($tracking_code, $document_id)
    {
        $qrCode = QrCode::size(200)->generate($tracking_code);
        return view('guest.success', [
            'tracking_code' => $tracking_code,
            'qrCode' => $qrCode,
            'document_id' => $document_id
        ]);
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
            // Trim each code to prevent issues with whitespace in URL
            $trackingCodes = array_map('trim', array_filter(explode(',', $codesParam)));
        }

        if (empty($trackingCodes)) {
            // If no codes, redirect to welcome or show a specific message
            return redirect()->route('welcome')->with('info', 'Please enter a tracking code to view its status.');
        }

        $documents = Document::with(['purpose', 'logs'])
                            ->whereIn('tracking_code', $trackingCodes)
                            ->get();

        return view('guest.track', ['documents' => $documents]);
    }

    /**
     * Get a single document card for AJAX requests.
     *
     * @param string $tracking_code
     * @return \Illuminate\Http\Response
     */
    public function getTrackedDocumentModule($tracking_code)
    {
        $document = Document::with(['purpose', 'logs'])->where('tracking_code', $tracking_code)->firstOrFail();

        // Render the component as a string
        $html = view('general.components.document-card', ['document' => $document])->render();

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
