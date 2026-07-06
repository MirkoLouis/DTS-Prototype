<!-- QR Scanner Modal -->
<div id="qr-modal" class="fixed z-50 inset-0 overflow-y-auto hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 transition-opacity bg-gray-500/75 dark:bg-gray-900/75" aria-hidden="true"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="relative z-10 inline-block align-bottom bg-white dark:bg-gray-800 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
            <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <h3 class="text-lg leading-6 font-medium text-gray-900 dark:text-gray-100 mb-4" id="modal-title">
                    Scan QR Code
                </h3>
                <div id="qr-reader" style="width: 100%;"></div>
            </div>
            <div class="bg-gray-50 dark:bg-gray-700 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button type="button" id="close-qr-modal-btn" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-accent-1 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm dark:bg-accent-2 dark:text-gray-200 dark:border-gray-500 dark:hover:bg-accent-2-hover">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<script src="/js/html5-qrcode.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const qrModal = document.getElementById('qr-modal');
        const scanBtn = document.getElementById('scan-qr-btn');
        const closeBtn = document.getElementById('close-qr-modal-btn');
        
        // This partial expects the page to have inputs with id="tracking_code" and form id="scan-form" (or find-form)
        const trackingInput = document.getElementById('tracking_code');
        const targetForm = document.getElementById('scan-form') || document.getElementById('find-form');
        
        let html5QrcodeScanner = null;

        function onScanSuccess(decodedText, decodedResult) {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.clear().then(() => {
                    qrModal.classList.add('hidden');
                    if (trackingInput && targetForm) {
                        trackingInput.value = decodedText;
                        targetForm.submit();
                    }
                });
            }
        }

        if (scanBtn) {
            scanBtn.addEventListener('click', () => {
                qrModal.classList.remove('hidden');
                if (!html5QrcodeScanner) {
                    html5QrcodeScanner = new Html5QrcodeScanner(
                        "qr-reader", { fps: 10, qrbox: 250 });
                }
                html5QrcodeScanner.render(onScanSuccess);
            });
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                if (html5QrcodeScanner) {
                    html5QrcodeScanner.clear().then(() => {
                        qrModal.classList.add('hidden');
                    });
                } else {
                    qrModal.classList.add('hidden');
                }
            });
        }
    });
</script>
