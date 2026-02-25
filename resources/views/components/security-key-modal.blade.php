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
                    <h3 class="text-2xl font-bold">Initialize Digital Signature</h3>
                </div>
                
                <p class="mb-4 text-gray-600 dark:text-gray-400">
                    To ensure <strong>Non-Repudiation</strong>, your department must initialize a unique digital signature. This can be an official phrase, a code, or any identifier of your choosing that will be used to "sign" every action performed by this account.
                </p>

                <div class="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-md mb-6 border-l-4 border-blue-500">
                    <p class="text-sm text-blue-700 dark:text-blue-300">
                        <strong>Thesis Note:</strong> Every movement in the ledger is cryptographically tied to this signature, proving which department authorized the action.
                    </p>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Department Digital Signature</label>
                    <div class="flex gap-2">
                        <input type="text" id="generated-key" class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono" placeholder="Type your department's custom signature...">
                        <button type="button" id="copy-signature-btn" class="inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" title="Copy to Clipboard">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path>
                            </svg>
                        </button>
                        <button type="button" id="regenerate-key-btn" class="inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" title="Suggest a Random Signature">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                            </svg>
                        </button>
                    </div>
                    <p class="mt-2 text-xs font-bold text-red-600 dark:text-red-400">⚠️ IMPORTANT: Please copy and save this signature in a secure place (Notes, Password Manager, etc.). You might need it in the future.</p>
                </div>

                <div class="flex justify-end">
                    <button id="initialize-key-btn" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:border-indigo-700 focus:ring focus:ring-indigo-200 transition">
                        Confirm & Initialize Signature
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
        const regenerateBtn = document.getElementById('regenerate-key-btn');
        const copyBtn = document.getElementById('copy-signature-btn');

        // Only show if the user is logged in and has no public key
        @auth
            @if(!auth()->user()->public_key && auth()->user()->role !== 'admin')
                showSecurityModal();
            @endif
        @endauth

        function showSecurityModal() {
            generateRandomKey();
            modal.style.display = 'block';
        }

        function generateRandomKey() {
            // Generate a random "Department Key" (Simulating a public key for the prototype)
            const randomKey = 'DTS-PUB-' + Math.random().toString(36).substring(2, 15).toUpperCase() + 
                              '-' + Math.random().toString(36).substring(2, 15).toUpperCase();
            keyInput.value = randomKey;
        }

        if (regenerateBtn) {
            regenerateBtn.addEventListener('click', generateRandomKey);
        }

        if (copyBtn) {
            copyBtn.addEventListener('click', function() {
                const textToCopy = keyInput.value;
                if (!textToCopy) return;

                if (!navigator.clipboard) {
                    fallbackCopyTextToClipboard(textToCopy);
                    showCopySuccess(copyBtn);
                    return;
                }

                navigator.clipboard.writeText(textToCopy).then(() => {
                    showCopySuccess(copyBtn);
                }).catch(err => {
                    console.error('Could not copy text: ', err);
                });
            });
        }

        function fallbackCopyTextToClipboard(text) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.position = "fixed";
            textArea.style.opacity = 0;
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                document.execCommand('copy');
            } catch (err) {
                console.error('Fallback: Oops, unable to copy', err);
            }
            document.body.removeChild(textArea);
        }

        function showCopySuccess(button) {
            const originalIcon = button.innerHTML;
            button.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-green-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>';
            setTimeout(() => {
                button.innerHTML = originalIcon;
            }, 2000);
        }

        initBtn.addEventListener('click', async function() {
            const key = keyInput.value;
            
            try {
                const response = await fetch('{{ route('security.key.store') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ public_key: key })
                });

                if (!response.ok) {
                    const errorData = await response.json();
                    throw new Error(errorData.message || 'Server error');
                }

                const result = await response.json();
                if (result.status === 'success') {
                    modal.style.display = 'none';
                    // Reload to update the user session/state
                    window.location.reload();
                }
            } catch (error) {
                console.error('Error storing signature:', error);
                alert(error.message || 'Failed to initialize digital signature. Please try again.');
            }
        });
    });
</script>
