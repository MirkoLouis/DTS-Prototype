<?php
/**
 * Reusable Modal Component
 * 
 * Expected variables:
 * @var string $modalId
 * @var string $modalTitle
 * @var string $modalSize (e.g. 'max-w-md', 'max-w-4xl', etc)
 * @var string $modalContent
 * @var string $modalFooter (optional)
 * @var bool $hideCloseButton (optional)
 */
$modalId = $modalId ?? 'default-modal';
$modalTitle = $modalTitle ?? '';
$modalSize = $modalSize ?? 'max-w-md';
$modalContent = $modalContent ?? '';
$modalFooter = $modalFooter ?? '';
$hideCloseButton = $hideCloseButton ?? false;
?>
<div id="<?= htmlspecialchars($modalId) ?>" class="fixed inset-0 z-[70] hidden items-center justify-center bg-gray-900/75 transition-opacity">
    <div class="relative z-10 w-full <?= htmlspecialchars($modalSize) ?> p-4 md:p-6 bg-white dark:bg-gray-800 rounded-lg shadow-xl mx-4 max-h-[90vh] overflow-y-auto">
        
        <?php if ($modalTitle || !$hideCloseButton): ?>
        <div class="flex items-center justify-between mb-4 border-b dark:border-gray-700 pb-2">
            <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100"><?= htmlspecialchars($modalTitle) ?></h3>
            
            <?php if (!$hideCloseButton): ?>
            <button type="button" class="text-gray-400 hover:text-gray-500 close-modal-btn" data-modal="<?= htmlspecialchars($modalId) ?>">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="mb-6 text-gray-900 dark:text-gray-100">
            <?= $modalContent ?>
        </div>

        <?php if ($modalFooter): ?>
            <div class="flex justify-end space-x-3">
                <?= $modalFooter ?>
            </div>
        <?php endif; ?>
    </div>
</div>
