@props(['document'])

<div class="card shadow-sm mb-4">
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
                            @case('rejected') text-bg-danger @break
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
        <div class="subway-map-wrapper">
            @if($document->status == 'pending' || empty($document->finalized_route))
                <div class="alert alert-info text-center">
                    This document has been submitted and is waiting to be accepted by a Records Officer.
                    The route will be displayed here once it is finalized.
                </div>
            @else
                <x-tracker-subway-map :finalized_route="$document->finalized_route" :current_step="$document->current_step" />
            @endif
        </div>
    </div>
</div>
