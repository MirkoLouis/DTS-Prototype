<?php ob_start(); ?>
<h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
    Request Document Return
</h2>
<?php $header = ob_get_clean(); ?>

<?php ob_start(); ?>
<div class="py-0">
    <div class="mx-[20vh] sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">
                <h3 class="text-2xl font-bold mb-4">Request a Document to be Rerouted</h3>
                <p class="mb-6 text-gray-600 dark:text-gray-400">If you need to make corrections to a document that has already passed your department, you can request for it to be rerouted. Your department will be added to the document's route immediately after its current step.</p>
                
                <form id="return-request-form" action="/return-requests" method="POST">
                    <div class="space-y-4">
                        <div>
                            <label for="tracking_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tracking Code</label>
                            <div class="mt-1">
                                <input type="text" name="tracking_code" id="tracking_code" class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-accent-1 focus:ring-accent-1 sm:text-sm p-2 border" placeholder="Enter the document's tracking code" required>
                            </div>
                            <button type="button" id="scan-qr-button" class="mt-2 inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-accent-1 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-accent-1">
                                Scan QR Code
                            </button>
                        </div>

                        <div>
                            <label for="reason" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Reason for Request</label>
                            <div class="mt-1">
                                <textarea name="reason" id="reason" rows="4" class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-accent-1 focus:ring-accent-1 sm:text-sm p-2 border" placeholder="Please provide a clear reason for needing the document rerouted..." required></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6">
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-yellow-500 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-yellow-400 active:bg-yellow-600 focus:outline-none focus:border-yellow-600 focus:ring focus:ring-yellow-200 transition">
                            Submit Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- QR Scanner Modal Placeholder -->
<div id="qr-scanner-modal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50" style="display: none;">
    <div class="bg-white rounded-lg p-6 max-w-md w-full">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold">Scan QR Code</h3>
            <button id="close-qr-modal" class="text-gray-500 hover:text-gray-700 font-bold text-xl">&times;</button>
        </div>
        <div id="qr-reader" style="width: 100%;"></div>
        <p class="text-sm text-gray-500 text-center mt-4">Point your camera at the document's QR code.</p>
    </div>
</div>
<?php $content = ob_get_clean(); ?>

<?php require BASE_PATH . '/src/Views/layouts/app.php'; ?>
