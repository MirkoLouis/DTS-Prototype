@props(['document'])

<div 
    class="card shadow-sm mb-4 document-card" 
    data-tracking-code="{{ $document->tracking_code }}"
    data-status="{{ $document->status }}"
    data-current-step="{{ $document->current_step }}"
>
    <div class="card-header text-center">
        <h2 class="h4 mb-0">Document Status: {{ $document->tracking_code }}</h2>
    </div>
    <div class="card-body p-4">
        <div class="row mb-4">
            <div class="col-md-6">
                <p><strong>Submitter:</strong> {{ $document->guest_info['name'] }}</p>
                <p><strong>Purpose:</strong> {{ $document->purpose->name }}</p>
            </div>
            <div class="col-md-6">
                <p><strong>Status:</strong> 
                    <span class="badge 
                        @switch($document->status)
                            @case('pending') text-bg-warning @break
                            @case('processing') text-bg-primary @break
                            @case('completed') text-bg-success @break
                            @case('declined') text-bg-danger @break
                            @default text-bg-secondary
                        @endswitch
                    ">
                        {{ ucfirst($document->status) }}
                    </span>
                </p>
                <p><strong>Submitted On:</strong> {{ $document->created_at->format('M d, Y h:i A') }}</p>
            </div>
        </div>

        <hr>

        <h3 class="h5 mt-4 mb-3">Tracking History</h3>

        @php
            $lastLog = $document->logs->last();
            $wasJustRerouted = $lastLog && in_array($lastLog->action, ['Rerouted', 'Returned from Releasing']);
        @endphp

        {{-- Show a temporary notice if the document was just rerouted --}}
        @if ($wasJustRerouted)
            <div class="alert alert-info small mb-3">
                <p class="mb-0"><i class="bi bi-info-circle-fill"></i> This document's route was just updated for additional processing and is now in transit.</p>
            </div>
        @endif

        @php
            $isFinalTransit = $document->status == 'in_transit' && $document->current_step > count($document->finalized_route ?? []);
        @endphp
        <div class="subway-map-wrapper">
            @if($document->status == 'pending')
                <div class="alert alert-info text-center">
                    This document has been submitted and is waiting to be accepted by a Records Officer.
                    The route will be displayed here once it is finalized.
                </div>
            @elseif($document->status == 'declined')
                <div class="alert alert-danger text-center">
                    <h4 class="alert-heading">Document Declined</h4>
                    <p class="mb-0"><strong>Reason:</strong> <span class="fw-bold">{{ $document->decline_reason ?? 'No reason provided.' }}</span></p>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                        For more information and to retrieve your document, please visit the Records Section.
                    </p>
                </div>
            @elseif ($isFinalTransit)
                <div class="alert alert-info text-center">
                    <h4 class="alert-heading">Processing Finished</h4>
                    <p class="mb-0">All processing steps are complete. The document is now in transit back to the Records Department to be ready for releasing.</p>
                </div>
            @elseif($document->status == 'ready_for_release')
                <div class="alert alert-success text-center">
                    <h4 class="alert-heading">Processing Complete!</h4>
                    <p class="mb-0">Your document has finished internal processing and is now ready for release at the Records Department.</p>
                </div>
            @else {{-- Status is 'processing', 'in_transit', or 'completed' --}}
                <x-tracker-subway-map :route_objects="$document->display_route_objects" :current_step="$document->display_current_step" />
            @endif
        </div>
    </div>
</div>



