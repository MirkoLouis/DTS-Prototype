{{-- 
    QR Scanner Modal Component
    This component provides a consistent, styled modal for QR code scanning.
    Updated to use Tailwind CSS classes for better theme integration.
--}}
<div id="qr-scanner-modal" class="fixed inset-0 z-[60] overflow-y-auto" style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
        <!-- Backdrop -->
        <div class="fixed inset-0 transition-opacity bg-black/60" aria-hidden="true"></div>

        <!-- Spacer for centering -->
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

        <!-- Modal Content -->
        <div class="relative z-10 inline-block align-bottom bg-gray-900 border border-gray-700 rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full p-6 text-white">
            <span id="close-qr-modal" class="text-gray-400 hover:text-white transition-colors float-right text-4xl font-bold absolute -top-1 right-2 cursor-pointer leading-none">&times;</span>
            <h3 class="text-2xl font-bold mb-4 text-center">Scan Document QR Code</h3>
            <div id="qr-reader" class="w-full rounded-md overflow-hidden bg-black"></div>
        </div>
    </div>
</div>
