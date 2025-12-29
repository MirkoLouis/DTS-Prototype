@props(['finalized_route' => [], 'current_step' => 0])

<style>
    .subway-map-container {
        display: flex;
        align-items: flex-start;
        overflow-x: auto;
        padding: 1rem 0;
    }
    .station {
        display: flex;
        flex-direction: column;
        align-items: center;
        position: relative;
        min-width: 120px; /* 30 * 4 */
    }
    .station-track {
        flex-grow: 1;
        height: 4px;
        background-color: #d1d5db; /* gray-300 */
        margin-top: 13px; /* Aligns with center of the circle */
    }
    .station:first-child .station-track-left {
        background-color: transparent;
    }
    .station:last-child .station-track-right {
        background-color: transparent;
    }

    .station-icon {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        border: 4px solid #d1d5db; /* gray-300 */
        background-color: #fff; /* white */
        z-index: 10;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        color: #6b7280; /* gray-500 */
    }
    .station-label {
        margin-top: 0.5rem;
        font-size: 0.875rem;
        font-weight: 500;
        text-align: center;
        color: #6b7280; /* gray-500 */
        width: 100%;
    }

    /* Completed State */
    .station.is-done .station-track { background-color: #10b981; /* green-500 */ }
    .station.is-done .station-icon { border-color: #10b981; /* green-500 */ color: #10b981; }
    .station.is-done .station-label { color: #059669; /* green-600 */ }

    /* Current State */
    .station.is-current .station-icon {
        border-color: #3b82f6; /* blue-500 */
        background-color: #dbeafe; /* blue-100 */
        color: #2563eb; /* blue-600 */
        animation: pulse 2s infinite;
    }
    .station.is-current .station-label {
        font-weight: 700;
        color: #1d4ed8; /* blue-700 */
    }
    .station.is-current .station-track-left { background-color: #10b981; /* green-500 */ }

    @keyframes pulse {
        0%, 100% {
            transform: scale(1);
            opacity: 1;
        }
        50% {
            transform: scale(1.1);
            opacity: 0.7;
        }
    }

    /* Dark mode styles */
    .dark .station-track { background-color: #4b5563; /* gray-600 */ }
    .dark .station-icon { border-color: #4b5563; /* gray-600 */ background-color: #374151; /* gray-700 */ color: #d1d5db; /* gray-300 */ }
    .dark .station-label { color: #9ca3af; /* gray-400 */ }
    
    .dark .station.is-done .station-track { background-color: #34d399; /* green-400 */ }
    .dark .station.is-done .station-icon { border-color: #34d399; /* green-400 */ color: #34d399; }
    .dark .station.is-done .station-label { color: #6ee7b7; /* green-300 */ }

    .dark .station.is-current .station-icon {
        border-color: #60a5fa; /* blue-400 */
        background-color: #1e3a8a; /* blue-900 */
        color: #93c5fd; /* blue-300 */
    }
    .dark .station.is-current .station-label { color: #bfdbfe; /* blue-200 */ }
    .dark .station.is-current .station-track-left { background-color: #34d399; /* green-400 */ }
</style>

<div class="subway-map-container">
    @foreach($finalized_route as $index => $step)
        @php
            $loopIndex = $index + 1;
            $isDone = $current_step > $loopIndex;
            $isCurrent = $current_step == $loopIndex;
            $isUpcoming = $current_step < $loopIndex;
        @endphp

        <div class="station 
            @if($isDone) is-done @endif 
            @if($isCurrent) is-current @endif
        ">
            <div class="flex items-center w-full">
                <div class="station-track station-track-left"></div>
                <div class="station-icon">
                    @if($isDone)
                        <span>&#10003;</span> {{-- Checkmark --}}
                    @else
                        {{ $loopIndex }}
                    @endif
                </div>
                <div class="station-track station-track-right"></div>
            </div>
            <div class="station-label">{{ $step }}</div>
        </div>
    @endforeach
</div>
