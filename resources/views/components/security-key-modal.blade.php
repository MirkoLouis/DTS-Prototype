<div id="security-key-modal" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 transition-opacity" aria-hidden="true">
            <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
        </div>

        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

        <div class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
            <div class="p-6">
                <div class="flex items-center mb-4 text-indigo-600 dark:text-indigo-400">
                    <svg class="w-8 h-8 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                    <h3 class="text-2xl font-bold">Initialize Security Key</h3>
                </div>
                
                <p class="mb-4 text-gray-600 dark:text-gray-400">
                    To ensure <strong>Non-Repudiation</strong>, your department must initialize a unique security key. This key will be used to "sign" every action performed by this account, proving its authenticity.
                </p>

                <div class="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-md mb-6 border-l-4 border-blue-500">
                    <p class="text-sm text-blue-700 dark:text-blue-300">
                        <strong>Thesis Note:</strong> In a real-world scenario, this would be an RSA Private/Public keypair. For this prototype, we are generating a unique cryptographic identifier for your department.
                    </p>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Department Security Key (Public)</label>
                    <div class="flex">
                        <input type="text" id="generated-key" readonly class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono" value="">
                    </div>
                </div>

                <div class="flex justify-end">
                    <button id="initialize-key-btn" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:border-indigo-700 focus:ring focus:ring-indigo-200 transition">
                        Confirm & Initialize Key
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('security-key-modal');
        const keyInput = document.getElementById('generated-key');
        const initBtn = document.getElementById('initialize-key-btn');

        // Only show if the user is logged in and has no public key
        @auth
            @if(!auth()->user()->public_key && auth()->user()->role !== 'admin')
                showSecurityModal();
            @endif
        @endauth

        function showSecurityModal() {
            // Generate a random "Department Key" (Simulating a public key for the prototype)
            const randomKey = 'DTS-PUB-' + Math.random().toString(36).substring(2, 15).toUpperCase() + 
                              '-' + Math.random().toString(36).substring(2, 15).toUpperCase();
            keyInput.value = randomKey;
            modal.style.display = 'block';
        }

        initBtn.addEventListener('click', async function() {
            const key = keyInput.value;
            
            try {
                const response = await fetch('{{ route('security.key.store') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ public_key: key })
                });

                const result = await response.json();
                if (result.status === 'success') {
                    modal.style.display = 'none';
                    // Optional: show a success toast
                    alert(result.message);
                }
            } catch (error) {
                console.error('Error storing key:', error);
                alert('Failed to initialize security key. Please try again.');
            }
        });
    });
</script>
