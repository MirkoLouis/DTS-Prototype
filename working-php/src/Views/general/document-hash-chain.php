<?php $title = 'Hash Chain — ' . ($document['tracking_code'] ?? 'Document'); ob_start(); ?>
<h1 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
    Hash Chain for Document: <?php echo htmlspecialchars($document['tracking_code']); ?>
</h1>
<?php $header = ob_get_clean(); ?>

<?php ob_start(); ?>
<div class="py-10">
    <div class="px-4 sm:px-6 lg:px-8 xl:mx-[20vh] space-y-6">
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">
                <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:justify-between sm:items-center">
                    <h2 class="text-lg font-bold">Document: <?php echo htmlspecialchars($document['tracking_code']); ?></h2>
                    <a href="/documents/<?php echo htmlspecialchars($document['tracking_code']); ?>" class="inline-flex items-center px-4 py-2 bg-accent-2 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-accent-2-hover active:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-accent-1 transition ease-in-out duration-150">
                        Back to Document
                    </a>
                </div>

                <?php
                    // One hash cell: the hash text with a copy button, or "N/A" when there is no hash
                    $hashCell = function (string $idPrefix, $logId, string $hash, string $copyLabel): string {
                        if ($hash === 'N/A' || empty($hash)) {
                            return '<span class="font-mono text-gray-400">N/A</span>';
                        }
                        return sprintf(
                            '<div class="flex items-start justify-between gap-1 group">
                                <span id="%s-%s" class="font-mono break-all text-gray-500 dark:text-gray-400" title="%s">%s</span>
                                %s
                            </div>',
                            $idPrefix,
                            $logId,
                            htmlspecialchars($hash),
                            htmlspecialchars($hash),
                            copy_button($hash, $copyLabel, 'h-3.5 w-3.5', 'text-gray-400 hover:text-accent-1 focus:ring-accent-1 shrink-0 mt-0.5')
                        );
                    };

                    foreach ($logs as &$log) {
                        $log['user_name_text'] = htmlspecialchars($log['user_name'] ?? 'System');
                        
                        $log['prev_hash_html'] = $hashCell('prev-hash', $log['id'], $log['previous_hash'] ?? 'N/A', 'Copy Previous Hash');
                        $log['curr_hash_html'] = $hashCell('curr-hash', $log['id'], $log['hash'] ?? 'N/A', 'Copy Current Hash');
                    }
                    unset($log);

                    $tableConfig = [
                        'wrapper_classes' => 'overflow-x-auto',
                        'columns' => [
                            ['key' => 'created_at', 'label' => 'Timestamp', 'width' => 'w-[12%]', 'type' => 'date'],
                            ['key' => 'action', 'label' => 'Action', 'width' => 'w-[24%]', 'wrap' => true],
                            ['key' => 'user_name_text', 'label' => 'Performed By', 'width' => 'w-[14%]', 'wrap' => true],
                            ['key' => 'prev_hash_html', 'label' => 'Previous Hash', 'width' => 'w-[25%]', 'type' => 'raw', 'wrap' => true],
                            ['key' => 'curr_hash_html', 'label' => 'Current Hash', 'width' => 'w-[25%]', 'type' => 'raw', 'wrap' => true]
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
