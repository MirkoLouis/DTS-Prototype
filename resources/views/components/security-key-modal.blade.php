<div id="security-key-modal" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
        <!-- Backdrop -->
        <div class="fixed inset-0 transition-opacity bg-gray-500/75 dark:bg-gray-900/75" aria-hidden="true"></div>

        <!-- This element is to trick the browser into centering the modal contents. -->
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

        <!-- Modal Content -->
        <div class="relative z-10 inline-block align-bottom bg-white dark:bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
            <div class="p-6">
                <div class="flex items-center mb-4 text-indigo-600 dark:text-indigo-400">
                    <svg class="w-8 h-8 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                    <h3 class="text-2xl font-bold">Initialize Digital Signature</h3>
                </div>
                
                <p class="mb-4 text-gray-600 dark:text-gray-400">
                    To ensure <strong>Non-Repudiation</strong>, your account must initialize a cryptographic <strong>Ed25519 Keypair</strong>. This allows you to "sign" actions with mathematical proof of identity.
                </p>

                <div class="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-md mb-6 border-l-4 border-blue-500">
                    <p class="text-sm text-blue-700 dark:text-blue-300">
                        <strong>Security Protocol:</strong> We will generate a keypair on the server. Your private key will be encrypted using a <strong>Secret PIN</strong> that only you know.
                    </p>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Create your Secret Signing PIN (4-16 characters)
                    </label>
                    <input type="password" id="signing-pin" class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" placeholder="Enter a secure PIN..." minlength="4" maxlength="16">
                    <p class="mt-2 text-xs text-gray-500">This PIN will be required whenever you receive or release a document.</p>
                </div>

                <div class="flex justify-end">
                    <button id="initialize-key-btn" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:border-indigo-700 focus:ring focus:ring-indigo-200 transition">
                        Generate & Initialize Signature
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('security-key-modal');
        const pinInput = document.getElementById('signing-pin');
        const initBtn = document.getElementById('initialize-key-btn');

        @auth
            @if(!auth()->user()->public_key)
                modal.style.display = 'block';
                pinInput.focus();
            @endif
        @endauth

        pinInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                initBtn.click();
            }
        });

        initBtn.addEventListener('click', async function() {
            const pin = pinInput.value;

            if (pin.length < 4) {
                alert('PIN must be at least 4 characters long.');
                return;
            }
            
            initBtn.disabled = true;
            initBtn.textContent = 'Generating...';

            try {
                const response = await fetch('{{ route('security.key.store') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ pin: pin })
                });

                if (!response.ok) {
                    const errorData = await response.json();
                    throw new Error(errorData.message || 'Server error');
                }

                const result = await response.json();
                if (result.status === 'success') {
                    modal.style.display = 'none';
                    window.location.reload();
                }
            } catch (error) {
                console.error('Error generating keys:', error);
                alert(error.message || 'Failed to initialize digital signature. Please try again.');
                initBtn.disabled = false;
                initBtn.textContent = 'Generate & Initialize Signature';
            }
        });
    });
</script>
