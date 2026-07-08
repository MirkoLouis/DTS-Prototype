<?php
/**
 * Reusable Modal Component
 * 
 * Expected variables:
 * @var string $modalId          Unique identifier for the modal
 * @var string $modalTitle       Title displayed in the modal header
 * @var int    $modalSize        Size of the modal on a scale of 1-10 (default: 4)
 * @var bool   $hideCloseButton  If true, hides the 'X' close button (default: false)
 * @var string $modalContent     The HTML content of the modal body
 * @var string $modalFooter      The HTML content of the modal footer
 */

$modalId = $modalId ?? 'default-modal';
$modalTitle = $modalTitle ?? 'Modal Title';
$modalSize = $modalSize ?? 4; // Default to size 4 (max-w-lg equivalent)
$hideCloseButton = $hideCloseButton ?? false;
$modalContent = $modalContent ?? '';
$modalFooter = $modalFooter ?? '';

// Map size 1-10 to Tailwind max-w classes
$sizeMap = [
    1 => 'max-w-xs',
    2 => 'max-w-sm',
    3 => 'max-w-md',
    4 => 'max-w-lg',
    5 => 'max-w-xl',
    6 => 'max-w-2xl',
    7 => 'max-w-3xl',
    8 => 'max-w-4xl',
    9 => 'max-w-5xl',
    10 => 'max-w-6xl'
];

$sizeClass = $sizeMap[$modalSize] ?? 'max-w-lg'; // Fallback to max-w-lg if out of bounds

// If the user passed a string (like max-w-4xl directly), use it instead to preserve backwards compatibility during transition.
if (is_string($modalSize) && !is_numeric($modalSize)) {
    $sizeClass = $modalSize;
}
?>
<div id="<?= htmlspecialchars($modalId) ?>" tabindex="-1" aria-hidden="true" class="fixed inset-0 z-50 hidden w-full p-4 overflow-x-hidden overflow-y-auto h-full flex items-center justify-center">
    <!-- Backdrop -->
    <div class="fixed inset-0 bg-gray-900 bg-opacity-50 dark:bg-opacity-80 transition-opacity close-modal-backdrop" data-modal="<?= htmlspecialchars($modalId) ?>"></div>
    
    <!-- Modal Panel -->
    <div class="relative w-full <?= $sizeClass ?> max-h-full z-10 transition-all transform">
        <!-- Modal content -->
        <div class="relative bg-white rounded-lg shadow-xl dark:bg-gray-800 flex flex-col max-h-[90vh]">
            <!-- Modal header -->
            <div class="flex items-center justify-between p-4 border-b rounded-t dark:border-gray-700 shrink-0">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white" id="modal-title">
                    <?= htmlspecialchars($modalTitle) ?>
                </h3>
                <?php if (!$hideCloseButton): ?>
                    <button type="button" class="close-modal-btn text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm p-1.5 ml-auto inline-flex items-center dark:hover:bg-gray-600 dark:hover:text-white transition-colors" data-modal="<?= htmlspecialchars($modalId) ?>">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>  
                    </button>
                <?php endif; ?>
            </div>
            <!-- Modal body -->
            <div class="p-6 space-y-6 text-gray-700 dark:text-gray-300 overflow-y-auto">
                <?= $modalContent ?>
            </div>
            <!-- Modal footer -->
            <?php if (!empty($modalFooter)): ?>
                <div class="flex items-center justify-end p-4 border-t border-gray-200 rounded-b dark:border-gray-700 shrink-0 space-x-2">
                    <?= $modalFooter ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
