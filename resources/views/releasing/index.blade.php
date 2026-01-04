<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Document Releasing') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h2 class="text-2xl font-bold mb-4">Documents Awaiting Release</h2>
                    
                    {{-- Session Messages --}}
                    @if (session('success'))
                        <div class="mb-4 p-4 bg-green-100 text-green-800 rounded-lg">
                            {{ session('success') }}
                        </div>
                    @endif
                    @if (session('error'))
                        <div class="mb-4 p-4 bg-red-100 text-red-800 rounded-lg">
                            {{ session('error') }}
                        </div>
                    @endif

                    {{-- This container will hold the table of documents --}}
                    <div id="releasing-container">
                        @include('partials.releasing-table', ['documents' => $documents])
                    </div>

                </div>
            </div>
        </div>
    </div>
    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const POLLING_INTERVAL = 60000; // 60 seconds

            const refreshReleasingList = async () => {
                try {
                    const response = await fetch('{{ route("releasing") }}', {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    if (!response.ok) {
                        console.error('Failed to refresh releasing list.');
                        return;
                    }
                    const html = await response.text();
                    const container = document.getElementById('releasing-container');
                    if (container) {
                        container.innerHTML = html;
                    }
                } catch (error) {
                    console.error('Error refreshing releasing list:', error);
                }
            };

            setInterval(refreshReleasingList, POLLING_INTERVAL);
        });
    </script>
    @endpush
</x-app-layout>
