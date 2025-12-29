<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Staff Tasks') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h2 class="text-2xl font-bold mb-4">Documents Awaiting Action</h2>
                    
                    {{-- This container will be the target for our AJAX updates --}}
                    <div id="tasks-container">
                        @include('partials.tasks-list', ['documents' => $documents])
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const POLLING_INTERVAL = 10000; // 10 seconds

            const refreshTasks = async () => {
                try {
                    const response = await fetch('{{ route("tasks") }}', {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    if (!response.ok) {
                        console.error('Failed to refresh tasks.');
                        return;
                    }
                    const html = await response.text();
                    const tasksContainer = document.getElementById('tasks-container');
                    if (tasksContainer) {
                        tasksContainer.innerHTML = html;
                    }
                } catch (error) {
                    console.error('Error refreshing tasks:', error);
                }
            };

            setInterval(refreshTasks, POLLING_INTERVAL);
        });
    </script>
</x-app-layout>
