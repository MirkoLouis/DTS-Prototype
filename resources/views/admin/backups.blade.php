<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Backup Manager ("The Safety Net")') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            <!-- Action Buttons -->
            <div class="flex justify-end mb-6">
                <form id="create-backup-form" action="{{ route('system.backups.create') }}" method="POST">
                    @csrf
                    <button id="create-backup-button" type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                        Create Database Backup Now
                    </button>
                </form>
            </div>

            <!-- Session Messages -->
            @if (session('success'))
                <div id="success-message" class="mb-4 p-4 bg-green-100 text-green-800 rounded-lg dark:bg-green-900 dark:text-green-200">
                    {{ session('success') }}
                </div>
            @endif
            @if (session('error'))
                <div class="mb-4 p-4 bg-red-100 text-red-800 rounded-lg dark:bg-red-900 dark:text-red-200">
                    {{ session('error') }}
                </div>
            @endif

            <!-- Backup List -->
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 dark:text-gray-100">
                    <h3 class="text-lg font-semibold mb-4">Available Backups</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">File Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Size</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="backup-list-body">
                                @forelse ($backups as $backup)
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">{{ $backup['last_modified']->format('M d, Y, h:i A') }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-mono">{{ $backup['file_name'] }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">{{ $backup['file_size'] }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                            <div class="flex justify-end items-center space-x-4">
                                                <a href="{{ route('system.backups.download', $backup['file_name']) }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-200">Download</a>
                                                <button type="button" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200" onclick="openRestoreModal('{{ $backup['file_name'] }}')">
                                                    Restore
                                                </button>
                                                <form action="{{ route('system.backups.delete', $backup['file_name']) }}" method="POST" onsubmit="return confirm('Are you sure you want to permanently delete this backup file? This action cannot be undone.');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-200">
                                                        Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-6 py-4 text-center text-gray-500 dark:text-gray-300">
                                            No backups found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Restore Confirmation Modal -->
    <x-modal name="restore-confirmation" focusable>
        <form id="restore-form" method="POST" class="p-6" onsubmit="return confirm('FINAL CONFIRMATION:\n\nAre you absolutely sure you want to restore the database? All current data will be lost.');">
            @csrf
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                <span class="text-red-500">FINAL WARNING:</span> Restore Database?
            </h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                You are about to replace the entire live database with the backup file <strong id="restore-file-name" class="font-mono"></strong>.
            </p>
            <p class="mt-2 text-lg font-bold text-red-500 dark:text-red-400 uppercase">
                All data created after this backup was made will be permanently lost.
            </p>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                This action is irreversible. The restore process will be queued and may take a few moments. The application will be in maintenance mode during the restore.
            </p>
            <div class="mt-6 flex justify-end">
                <x-secondary-button type="button" x-on:click="$dispatch('close')">
                    {{ __('Cancel') }}
                </x-secondary-button>
                <x-danger-button type="submit" class="ms-3">
                    {{ __('I Understand, Restore Now') }}
                </x-danger-button>
            </div>
        </form>
    </x-modal>

    @push('scripts')
    <script>
        function openRestoreModal(fileName) {
            document.getElementById('restore-file-name').textContent = fileName;
            const restoreForm = document.getElementById('restore-form');
            restoreForm.action = `/system/backups/restore/${fileName}`;
            window.dispatchEvent(new CustomEvent('open-modal', { detail: 'restore-confirmation' }));
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide the success message after 4 seconds
            const successMessage = document.getElementById('success-message');
            if (successMessage) {
                setTimeout(() => {
                    successMessage.style.transition = 'opacity 0.5s ease-out';
                    successMessage.style.opacity = '0';
                    setTimeout(() => successMessage.style.display = 'none', 500);
                }, 4000);
            }

            const createBackupForm = document.getElementById('create-backup-form');
            const createBackupButton = document.getElementById('create-backup-button');
            const initialBackupCount = {{ $backups->count() }};
            
            let pollingInterval;
            let pollingTimeout;

            if(createBackupForm) {
                createBackupForm.addEventListener('submit', function(event) {
                    event.preventDefault(); // Prevent page reload

                    createBackupButton.disabled = true;
                    createBackupButton.innerHTML = `
                        <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <span>Backup Queued...</span>
                    `;
                    
                    if (successMessage) successMessage.style.display = 'none';
                    
                    fetch('{{ route('system.backups.create') }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value,
                            'Accept': 'application/json',
                        }
                    })
                    .then(response => {
                        if (!response.ok) {
                           throw new Error('The backup job could not be started. Please check the logs.');
                        }
                        startPolling();
                    })
                    .catch(error => {
                        alert(error.message);
                        createBackupButton.disabled = false;
                        createBackupButton.textContent = 'Create Database Backup Now';
                    });
                });
            }

            function startPolling() {
                pollingInterval = setInterval(pollForNewBackup, 3000); 

                pollingTimeout = setTimeout(() => {
                    clearInterval(pollingInterval);
                    alert('Backup is taking longer than expected. Please check the server logs and try again later.');
                    createBackupButton.disabled = false;
                    createBackupButton.textContent = 'Create Database Backup Now';
                }, 120000);
            }

            function pollForNewBackup() {
                fetch('{{ route('system.backups.index') }}', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.length > initialBackupCount) {
                        clearInterval(pollingInterval);
                        clearTimeout(pollingTimeout);
                        alert('Backup created successfully!');
                        window.location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error polling for backups:', error);
                    clearInterval(pollingInterval);
                    clearTimeout(pollingTimeout);
                });
            }
        });
    </script>
    @endpush
</x-app-layout>