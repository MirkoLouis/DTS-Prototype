<!-- Digital Signature Modal -->
<div id="signing-modal" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
        <!-- Backdrop -->
        <div class="fixed inset-0 transition-opacity bg-gray-500/75 dark:bg-gray-900/75" aria-hidden="true"></div>

        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

        <!-- Modal Content -->
        <div class="relative z-10 inline-block align-bottom bg-white dark:bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full">
            <div class="p-6">
                <div class="flex items-center mb-4 text-accent-1 dark:text-accent-1-hover">
                    <svg class="w-8 h-8 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                    <h3 class="text-2xl font-bold">Digital Signature</h3>
                </div>
                
                <p id="signing-modal-message" class="mb-4 text-gray-600 dark:text-gray-400">
                    Please enter your Security PIN to cryptographically sign this transaction.
                </p>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Security PIN
                    </label>
                    <input type="password" id="signing-modal-pin" class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-accent-1 focus:ring-accent-1 sm:text-sm" placeholder="Enter your PIN..." required>
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button" id="signing-modal-cancel" class="inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-accent-1 sm:text-sm dark:bg-accent-2 dark:text-gray-200 dark:border-gray-500 dark:hover:bg-accent-2-hover">
                        Cancel
                    </button>
                    <button type="button" id="signing-modal-confirm" class="inline-flex items-center px-4 py-2 bg-accent-1 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-accent-1-hover active:bg-accent-1-active focus:outline-none focus:border-accent-1-border-active focus:ring focus:ring-accent-1-light transition">
                        Sign & Confirm
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    window.SigningModal = {
        callback: null,
        show: function(message, callback) {
            this.callback = callback;
            document.getElementById('signing-modal-message').innerText = message;
            document.getElementById('signing-modal-pin').value = '';
            document.getElementById('signing-modal').classList.remove('hidden');
            setTimeout(() => {
                document.getElementById('signing-modal-pin').focus();
            }, 100);
        },
        hide: function() {
            document.getElementById('signing-modal').classList.add('hidden');
            this.callback = null;
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        const confirmBtn = document.getElementById('signing-modal-confirm');
        const cancelBtn = document.getElementById('signing-modal-cancel');
        const pinInput = document.getElementById('signing-modal-pin');

        confirmBtn.addEventListener('click', function() {
            const pin = pinInput.value;
            if (!pin) {
                alert('Please enter your Security PIN.');
                pinInput.focus();
                return;
            }
            if (window.SigningModal.callback) {
                const cb = window.SigningModal.callback;
                window.SigningModal.hide();
                cb(pin);
            }
        });

        cancelBtn.addEventListener('click', function() {
            window.SigningModal.hide();
        });

        pinInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                confirmBtn.click();
            }
        });
    });
</script>
