<x-guest-layout>
    <div class="text-center py-4">
        <div class="mb-4">
            <h1 class="text-5xl font-extrabold text-red-600 dark:text-red-500">403</h1>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-2">{{ __('Access Denied') }}</h2>
        </div>
        
        <p class="text-gray-600 dark:text-gray-400 mb-8">
            {{ __('You do not have the required permissions to access this page.') }}
        </p>

        <div class="flex justify-center">
            <x-secondary-button onclick="history.back()">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                {{ __('Go Back') }}
            </x-secondary-button>
        </div>
    </div>
</x-guest-layout>
