@props(['status'])

@php
    $baseClasses = 'px-2 inline-flex text-xs leading-5 font-semibold rounded-full';
    $colorClasses = match (strtolower($status)) {
        'processing' => 'bg-blue-100 text-blue-800',
        'completed' => 'bg-green-100 text-green-800',
        'pending' => 'bg-yellow-100 text-yellow-800',
        'declined' => 'bg-red-100 text-red-800',
        'frozen' => 'bg-gray-100 text-gray-800',
        default => 'bg-gray-100 text-gray-800',
    };
@endphp

<span class="{{ $baseClasses }} {{ $colorClasses }}">
    {{ ucfirst($status) }}
</span>
