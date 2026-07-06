<?php ob_start(); ?>
<h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
    Hash Chain for Document: <?php echo htmlspecialchars($document['tracking_code']); ?>
</h2>
<?php $header = ob_get_clean(); ?>

<?php ob_start(); ?>
<div class="py-2">
    <div class="mx-[20vh] sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">
                <div class="mb-6 flex justify-between items-center">
                    <h3 class="text-lg font-bold">Document: <?php echo htmlspecialchars($document['tracking_code']); ?></h3>
                    <a href="/documents/<?php echo htmlspecialchars($document['tracking_code']); ?>" class="inline-flex items-center px-4 py-2 bg-accent-2 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-accent-2-hover active:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-accent-1 transition ease-in-out duration-150">
                        Back to Document
                    </a>
                </div>

                <?php
                    foreach ($logs as &$log) {
                        $log['user_name_text'] = htmlspecialchars($log['user_name'] ?? 'System');
                        $log['prev_hash_html'] = sprintf('<span id="prev-hash-%s" class="font-mono break-all">%s</span>', $log['id'], htmlspecialchars($log['previous_hash'] ?? 'N/A'));
                        $log['curr_hash_html'] = sprintf('<span id="curr-hash-%s" class="font-mono break-all">%s</span>', $log['id'], htmlspecialchars($log['hash'] ?? 'N/A'));
                    }
                    unset($log);

                    $tableConfig = [
                        'wrapper_classes' => 'overflow-x-auto',
                        'columns' => [
                            ['key' => 'created_at', 'label' => 'Timestamp', 'width' => 'w-[15%]', 'type' => 'date'],
                            ['key' => 'action', 'label' => 'Action', 'width' => 'w-[15%]', 'wrap' => true],
                            ['key' => 'user_name_text', 'label' => 'Performed By', 'width' => 'w-[15%]'],
                            ['key' => 'prev_hash_html', 'label' => 'Previous Hash', 'width' => 'w-[25%]', 'type' => 'raw'],
                            ['key' => 'curr_hash_html', 'label' => 'Current Hash', 'width' => 'w-[30%]', 'type' => 'raw']
                        ],
                        'data' => $logs,
                        'empty_message' => 'No hash chain history found for this document.'
                    ];
                    require BASE_PATH . '/src/Views/components/table.php';
                ?>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); ?>

<?php require BASE_PATH . '/src/Views/layouts/app.php'; ?>
