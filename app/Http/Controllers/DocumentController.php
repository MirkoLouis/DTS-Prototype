<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentLog;
use App\Jobs\UpdateKeywordWeights;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class DocumentController extends Controller
{
    /**
     * Show the form for managing a document's route.
     *
     * @param  \App\Models\Document  $document
     * @return \Illuminate\View\View
     */
    public function manage(Document $document)
    {
        \Illuminate\Support\Facades\Gate::authorize('manage', $document);

        // Eager load the purpose to get the suggested_route
        $document->load('purpose');
        $departments = Department::all();

        return view('officer.manage-documents', [
            'document' => $document,
            'departments' => $departments,
        ]);
    }

    /**
     * Finalize the document's route and put it into processing.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Document  $document
     * @return \Illuminate\Http\RedirectResponse
     */
    public function finalize(Request $request, Document $document)
    {
        \Illuminate\Support\Facades\Gate::authorize('manage', $document);

        $request->validate([
            'final_route' => 'required|json',
            'pin' => 'required|string',
        ]);

        $routeNames = json_decode($request->final_route);

        if (empty($routeNames)) {
            return back()->with('error', 'The route cannot be empty. Please add at least one step.');
        }

        $user = Auth::user();
        $firstDepartment = $routeNames[0];
        $action = 'Accepted and Document Routing finalized';
        $remarks = "Route finalized. In transit to {$firstDepartment}.";

        // 1. Generate Cryptographic Signature
        $dataToSign = $document->tracking_code . '|' . $action . '|' . $remarks . '|' . now()->toIso8601String();
        $signature = $user->sign($request->pin, $dataToSign);

        if ($signature === false) {
            return back()->with('error', 'Invalid Security PIN. Transaction aborted.');
        }

        if ($signature === null) {
            return back()->with('error', 'Your digital signature has not been initialized.');
        }

        // Convert the simple array of names into the new structured format.
        $finalizedRoute = array_map(function ($name) {
            return ['name' => $name, 'type' => 'initial'];
        }, $routeNames);

        // Update the document
        $document->update([
            'status' => 'in_transit',
            'finalized_route' => $finalizedRoute,
            'current_step' => 1,
        ]);

        // "Learn" from the officer's changes
        $purpose = $document->purpose;
        if ($purpose->suggested_route !== $routeNames) {
            if (!$purpose->is_official) {
                UpdateKeywordWeights::dispatch($purpose->name, $routeNames);
            }
            $purpose->update(['suggested_route' => $routeNames]);
        }

        // Create the initial document log with the real signature
        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => $user->id,
            'action' => $action,
            'remarks' => $remarks,
            'signature' => $signature,
        ]);

        return redirect()->route('intake')->with('success', 'Document accepted and is now in transit!');
    }

    /**
     * Decline a pending document.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Document  $document
     * @return \Illuminate\Http\RedirectResponse
     */
    public function decline(Request $request, Document $document)
    {
        \Illuminate\Support\Facades\Gate::authorize('manage', $document);

        // Ensure only pending documents can be declined
        if ($document->status !== 'pending') {
            return back()->with('error', 'This document cannot be declined as it is already being processed.');
        }

        $request->validate([
            'decline_reason' => 'required|string|max:1000',
        ]);

        $document->update([
            'status' => 'declined',
            'decline_reason' => $request->decline_reason,
            'declined_at' => now(),
        ]);

        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => Auth::id(),
            'action' => 'Document Declined',
            'remarks' => $request->decline_reason,
        ]);

        return redirect()->route('intake')->with('success', 'The document has been successfully declined.');
    }

    /**
     * Handle a document scan action.
     * This is the core of the new physical forwarding workflow.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function scan(Request $request)
    {
        $request->validate([
            'tracking_code' => 'required|string|exists:documents,tracking_code',
        ]);

        $document = Document::where('tracking_code', $request->tracking_code)->firstOrFail();
        $user = Auth::user()->load('department');

        // CASE 1: Document is ready to be received by the scanning user.
        if ($document->status === 'in_transit') {
            $route = $document->finalized_route;
            $currentStepIndex = $document->current_step - 1;

            // Is it waiting for the final receive by Records Unit?
            if ($currentStepIndex >= count($route)) {
                if ($user->department && $user->department->name === 'Records Unit') {
                    $document->update(['status' => 'ready_for_release']);
                    DocumentLog::create([
                        'document_id' => $document->id, 'user_id' => $user->id, 'action' => 'Ready for Releasing',
                        'remarks' => 'All processing steps completed. Document received by Records Unit for final releasing.',
                    ]);
                    return redirect()->route('releasing')->with('success', "Document {$document->tracking_code} is now ready for releasing.");
                }
            }
            // Is it waiting for an intermediate department?
            else {
                $responsibleDepartmentName = $route[$currentStepIndex]['name'];
                if ($user->department && $user->department->name === $responsibleDepartmentName) {
                    $document->update(['status' => 'processing']);
                    DocumentLog::create([
                        'document_id' => $document->id, 'user_id' => $user->id, 'action' => 'Received',
                        'remarks' => "Document received by {$user->department->name}.",
                    ]);
                    // Determine the redirect route based on user role
                    $userRole = Auth::user()->role;
                    $redirectRoute = ($userRole === 'officer') ? 'officer.tasks' : 'staff.tasks';
                    return redirect()->route($redirectRoute)->with('success', "Document {$document->tracking_code} has been received and added to your tasks.");
                }
            }

            // If we reach here, it means the document is in_transit, but not for the scanning user's department.
            $responsibleDepartmentName = ($currentStepIndex >= count($route)) ? 'the Records Unit' : ($route[$currentStepIndex]['name'] ?? 'an unknown department');
            return redirect()->back()->with('error', "This document is not for your department. It is waiting to be received by {$responsibleDepartmentName}.");
        }
        
        // CASE 2: Document is NOT in a receivable state. Provide specific feedback.
        $redirect = redirect()->back(); // Default redirect back to the page they scanned from (tasks or intake)

        switch ($document->status) {
            case 'processing':
                $currentStepIndex = $document->current_step - 1;
                $responsibleDepartmentName = $document->finalized_route[$currentStepIndex]['name'] ?? 'an unknown department';
                if ($user->department && $user->department->name === $responsibleDepartmentName) {
                    return $redirect->with('info', 'You are already processing this document.');
                }
                return $redirect->with('error', "This document is currently being processed by the {$responsibleDepartmentName}.");
            
            case 'pending':
                return $redirect->with('error', 'This document is still pending intake by the Records Office and cannot be received yet.');

            case 'ready_for_release':
                return $redirect->with('info', 'This document is already in the "Awaiting Release" list.');

            case 'completed':
            case 'declined':
                return $redirect->with('info', 'This document has already been released, please check your tracking code again.');
            
            default:
                // Fallback for any other status (like 'frozen')
                return $redirect->with('error', 'This document cannot be received at this time. Its current status is: ' . ucfirst($document->status));
        }
    }

    /**
     * Display the specified document's data and logs.
     *
     * @param  \App\Models\Document  $document
     * @return \Illuminate\View\View
     */
    public function show(Document $document, Request $request)
    {
        \Illuminate\Support\Facades\Gate::authorize('view', $document);

        $document->load(['purpose', 'logs.user']);

        // Default back URL based on role
        $role = Auth::user()->role;
        $defaultBackUrl = match($role) {
            'admin' => route('admin.dashboard'),
            'officer' => route('officer.tasks'),
            'staff' => route('staff.tasks'),
            default => route('dashboard'),
        };

        $backUrl = url()->previous();
        
        // Handle specific back_to redirection if provided via query param
        $backTo = $request->query('back_to');
        if ($backTo) {
            if ($backTo === 'integrity-monitor') {
                $backUrl = route('integrity-monitor');
            } elseif ($backTo === 'intake') {
                $backUrl = route('intake');
            } elseif ($backTo === 'releasing') {
                $backUrl = route('releasing');
            } elseif ($backTo === 'tasks') {
                $backUrl = match($role) {
                    'officer' => route('officer.tasks'),
                    'staff' => route('staff.tasks'),
                    default => $defaultBackUrl,
                };
            } elseif ($backTo === 'completed') {
                $backUrl = match($role) {
                    'officer' => route('officer.tasks.completed'),
                    'staff' => route('staff.tasks.completed'),
                    default => $defaultBackUrl,
                };
            } elseif (str_contains($backTo, config('app.url'))) {
                // If it's a raw URL, ensure it's from our own app
                $backUrl = $backTo;
            }
        }

        // If the previous URL is the same as the current one (e.g., after a refresh),
        // or if it's external, or if it's the login/logout page, use the default dashboard route.
        if (!$backUrl || $backUrl === url()->current() || !str_contains($backUrl, config('app.url'))) {
            $backUrl = $defaultBackUrl;
        }

        return view('general.show-document', [
            'document' => $document,
            'backUrl' => $backUrl,
            'backToKey' => $backTo, // Pass the key itself to preserve it in the "View Hash Chain" link
        ]);
    }

    /**
     * Display the hash chain for a specific document.
     *
     * @param  \App\Models\Document  $document
     * @return \Illuminate\View\View
     */
    public function showHashChain(Document $document, Request $request)
    {
        \Illuminate\Support\Facades\Gate::authorize('view', $document);

        $document->load(['logs.user']); // Load logs and the user associated with each log

        return view('general.document-hash-chain', [
            'document' => $document,
            'logs' => $document->logs()->orderBy('created_at')->get(), // Ensure logs are chronological
            'back_to' => $request->query('back_to'), // Pass the back_to parameter to the view
        ]);
    }

    /**
     * Freeze the specified document.
     *
     * @param  \App\Models\Document  $document
     * @return \Illuminate\Http\RedirectResponse
     */
    public function freeze(Document $document)
    {
        \Illuminate\Support\Facades\Gate::authorize('freeze', $document);

        $document->status = 'frozen';
        $document->save();

        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => Auth::id(),
            'action' => 'ADMIN: Document frozen.',
            'remarks' => 'An administrator has frozen this document, likely pending an integrity investigation.',
        ]);

        return response()->json(['status' => 'success', 'message' => 'Document has been frozen successfully.']);
    }

    /**
     * Unfreeze the specified document.
     *
     * @param  \App\Models\Document  $document
     * @return \Illuminate\Http\JsonResponse
     */
    public function unfreeze(Document $document)
    {
        \Illuminate\Support\Facades\Gate::authorize('unfreeze', $document);

        // Find the last status before it was frozen by looking at the second to last log entry
        // (The last log entry would be the 'frozen' action itself)
        $lastValidLog = $document->logs()
            ->where('action', '!=', 'ADMIN: Document frozen.')
            ->where('action', '!=', 'System Auto-Freeze')
            ->orderBy('id', 'desc')
            ->first();

        $previousStatus = $document->status; // Default to current if everything fails

        if ($lastValidLog) {
            // Map the last action to its likely status
            $previousStatus = match($lastValidLog->action) {
                'Submitted' => 'pending',
                'Accepted and Document Routing finalized' => 'in_transit',
                'Received' => 'processing',
                'Processing Complete' => 'in_transit',
                'Ready for Releasing' => 'ready_for_release',
                'Document Released' => 'completed',
                default => 'processing'
            };
        }

        $document->status = $previousStatus;
        $document->save();

        DocumentLog::create([
            'document_id' => $document->id,
            'user_id' => Auth::id(),
            'action' => 'ADMIN: Document unfrozen.',
            'remarks' => "An administrator has unfrozen this document, restoring its status to " . ucfirst($previousStatus) . ".",
        ]);

        return response()->json(['status' => 'success', 'message' => "Document has been unfrozen and restored to " . ucfirst($previousStatus) . "."]);
    }

    /**
     * Store a rating for the specified document.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Document  $document
     * @return \Illuminate\Http\JsonResponse
     */
    public function rate(Request $request, Document $document)
    {
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
        ]);

        if ($document->status !== 'completed') {
            return response()->json(['message' => 'This document cannot be rated yet.'], 422);
        }

        if ($document->rating !== null) {
            return response()->json(['message' => 'This document has already been rated.'], 422);
        }

        $document->rating = $validated['rating'];
        $document->save();

        return response()->json(['status' => 'success', 'message' => 'Thank you for your feedback!']);
    }

    /**
     * Generate a printable PDF tracking form for the specified document.
     *
     * @param  \App\Models\Document  $document
     * @return \Illuminate\Http\Response
     */
    public function printTrackingForm(Document $document)
    {
        // Eager load relationships to prevent N+1 issues in the view
        $document->load('purpose');

        $qrCode = base64_encode(QrCode::format('png')->size(110)->generate($document->tracking_code));

        $pdf = Pdf::loadView('general.tracking-form-pdf', [
            'document' => $document,
            'qrCode' => $qrCode,
        ]);

        return $pdf->setPaper('a4')->stream('document-tracking-form-'.$document->tracking_code.'.pdf');
    }
}

        