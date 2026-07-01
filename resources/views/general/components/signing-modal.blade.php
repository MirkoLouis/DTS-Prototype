<div id="signing-modal" class="fixed inset-0 z-[60] overflow-y-auto" style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
        <!-- Backdrop -->
        <div class="fixed inset-0 transition-opacity bg-gray-500/75 dark:bg-gray-900/75" aria-hidden="true"></div>

        <!-- Spacer for centering -->
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

        <!-- Modal Content -->
        <div class="relative z-10 inline-block align-bottom bg-white dark:bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full">
            <div class="p-6">
                <div class="flex items-center mb-4 text-indigo-600 dark:text-indigo-400">
                    <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                    </svg>
                    <h3 class="text-xl font-bold">Authorize Action</h3>
                </div>
                
                <p id="signing-modal-message" class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                    Please enter your Security PIN to cryptographically sign this transaction.
                </p>

                <div class="mb-6">
                    <label for="modal-signing-pin" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Security PIN
                    </label>
                    <input type="password" id="modal-signing-pin" onkeydown="window.SigningModal.handleKeydown(event)" class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" placeholder="Enter PIN..." autocomplete="off">
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancel-signing-btn" onclick="window.SigningModal.hide()" class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150">
                        Cancel
                    </button>
                    <button type="button" id="confirm-signing-btn" onclick="window.SigningModal.confirm()" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 active:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                        Sign & Confirm
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    if (!window.SigningModal) {
        window.SigningModal = {
            callback: null,
            
            show: function(message, callback) {
                this.callback = callback;
                document.getElementById('signing-modal-message').textContent = message;
                document.getElementById('modal-signing-pin').value = '';
                document.getElementById('signing-modal').style.display = 'block';
                setTimeout(() => document.getElementById('modal-signing-pin').focus(), 100);
            },
            
            hide: function() {
                document.getElementById('signing-modal').style.display = 'none';
                this.callback = null;
            },
            
            confirm: function() {
                const pin = document.getElementById('modal-signing-pin').value;
                if (pin.trim() === '') {
                    alert('Security PIN is required.');
                    return;
                }
                
                if (this.callback) {
                    this.callback(pin);
                }
                this.hide();
            },
            
            handleKeydown: function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.confirm();
                }
                if (e.key === 'Escape') {
                    this.hide();
                }
            }
        };
    }
</script>
