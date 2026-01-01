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
            @if($document->status == 'completed')
                <div id="rating-section-{{ $document->tracking_code }}">
                    @if($document->rating === null)
                        {{-- Rating Form --}}
                        <div class="text-center border p-4 rounded-3 bg-light">
                            <h4 class="h5">Thank you for using our service!</h4>
                            <p>Your document has been released. Please rate your experience.</p>
                            <div class="rating-stars my-3" data-tracking-code="{{ $document->tracking_code }}">
                                @for ($i = 1; $i <= 5; $i++)
                                    <span class="star fs-2" data-rating="{{ $i }}" style="cursor:pointer; color: #d3d3d3;" title="{{ $i }} stars">&#9733;</span>
                                @endfor
                            </div>
                            <div id="rating-feedback-{{ $document->tracking_code }}" class="text-danger small"></div>
                        </div>
                    @else
                        {{-- Already Rated --}}
                        <div class="alert alert-success text-center">
                            <h4 class="alert-heading">Thank You!</h4>
                            <p class="mb-0">We have received your rating of {{ $document->rating }} star(s). We appreciate your feedback.</p>
                        </div>
                    @endif
                </div>
            @elseif($document->status == 'pending' || empty($document->finalized_route))
                <div class="alert alert-info text-center">
                    This document has been submitted and is waiting to be accepted by a Records Officer.
                    The route will be displayed here once it is finalized.
                </div>
            @else {{-- Status is 'processing' or 'rejected' --}}
                @php
                    $totalSteps = count($document->finalized_route);
                @endphp

                @if ($document->current_step > $totalSteps)
                    {{-- This means it's finished internal processing and is ready for release --}}
                    <div class="alert alert-primary text-center">
                        <h4 class="alert-heading">Processing Complete!</h4>
                        <p class="mb-0">Your document has finished internal processing and is now ready for release at the Records Department.</p>
                    </div>
                @else
                    {{-- Still in processing, show the subway map --}}
                    <x-tracker-subway-map :finalized_route="$document->finalized_route" :current_step="$document->current_step" />
                @endif
            @endif
        </div>
    </div>
</div>

@once
<script>
// This script block will only be rendered once on the page, even if there are multiple document cards.
document.addEventListener('DOMContentLoaded', function () {
    function highlightStars(container, rating) {
        const stars = container.querySelectorAll('.star');
        stars.forEach(star => {
            star.style.color = star.dataset.rating <= rating ? '#ffc107' : '#d3d3d3';
        });
    }

    // Use event delegation on the body for dynamically added content
    document.body.addEventListener('mouseover', function(e) {
        if (e.target && e.target.classList.contains('star')) {
            const container = e.target.closest('.rating-stars');
            if (container && !container.dataset.rated) {
                highlightStars(container, e.target.dataset.rating);
            }
        }
    });

    document.body.addEventListener('mouseout', function(e) {
        if (e.target && e.target.classList.contains('star')) {
            const container = e.target.closest('.rating-stars');
            if (container && !container.dataset.rated) {
                highlightStars(container, 0); // Reset on mouse out
            }
        }
    });

    document.body.addEventListener('click', async function(e) {
        if (e.target && e.target.classList.contains('star')) {
            const container = e.target.closest('.rating-stars');
            if (container && !container.dataset.rated) {
                const rating = e.target.dataset.rating;
                const trackingCode = container.dataset.trackingCode;
                
                // Prevent further clicks
                container.dataset.rated = 'true'; 
                
                await submitRating(trackingCode, rating);
            }
        }
    });

    async function submitRating(trackingCode, rating) {
        const feedbackDiv = document.getElementById(`rating-feedback-${trackingCode}`);
        const ratingSection = document.getElementById(`rating-section-${trackingCode}`);

        try {
            // Note: On a public page, CSRF token is not available.
            // For a production app, consider a more secure method like a unique, single-use token per document.
            const response = await fetch(`/documents/${trackingCode}/rate`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ rating: rating })
            });
            
            const result = await response.json();

            if (response.ok) {
                ratingSection.innerHTML = `
                    <div class="alert alert-success text-center">
                        <h4 class="alert-heading">Thank You!</h4>
                        <p class="mb-0">We have received your rating of ${rating} star(s). We appreciate your feedback.</p>
                    </div>
                `;
            } else {
                feedbackDiv.textContent = result.message || 'An error occurred. Please try again.';
                // Allow user to try again if submission failed
                const container = ratingSection.querySelector('.rating-stars');
                if(container) container.dataset.rated = '';
            }
        } catch (error) {
            feedbackDiv.textContent = 'A network error occurred. Please check your connection and try again.';
            const container = ratingSection.querySelector('.rating-stars');
            if(container) container.dataset.rated = '';
        }
    }
});
</script>
@endonce

