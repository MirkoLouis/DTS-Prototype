<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Completed Documents') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- Documents Awaiting Action Section --}}
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h2 class="text-2xl font-bold mb-4">Previously Completed Documents</h2>
                    <p class="mb-6 text-gray-600 dark:text-gray-400">This is a list of documents you have previously processed. You can use their tracking codes to initiate a return request if needed.</p>

                    <div id="tasks-container">
                        @include('general.partials.completed-tasks-list', ['documents' => $documents])
                    </div>

                </div>
            </div>
        </div>
    </div>
</x-app-layout>
