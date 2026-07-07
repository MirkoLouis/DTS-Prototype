<?php
/**
 * Reusable Table Component
 * 
 * Expected variables:
 * @var array $tableConfig - Configuration array for the table
 * 
 * Format:
 * [
 *     'columns' => [
 *         [
 *             'key' => 'column_key', // The key in the data array
 *             'label' => 'Column Header',
 *             'width' => 'w-[20%]', // Tailwind width class
 *             'wrap' => true/false, // Whether text should wrap (max 2 lines) or nowrap
 *             'type' => 'text'|'tracking_link'|'status'|'date'|'action_view', // How to render the cell
 *         ]
 *     ],
 *     'data' => [ ... ], // Array of rows
 *     'empty_message' => 'No records found.'
 * ]
 */

$columns = $tableConfig['columns'] ?? [];
$data = $tableConfig['data'] ?? [];
$emptyMessage = $tableConfig['empty_message'] ?? 'No records found.';
$wrapperClasses = $tableConfig['wrapper_classes'] ?? 'overflow-x-auto';
?>

<div class="<?= htmlspecialchars($wrapperClasses) ?>">
    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-fixed border dark:border-gray-700">
        <thead class="bg-gray-50 dark:bg-gray-700">
            <tr>
                <?php foreach ($columns as $column): ?>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider <?= htmlspecialchars($column['width'] ?? '') ?>">
                        <?= htmlspecialchars($column['label']) ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
            <?php if (empty($data)): ?>
                <tr>
                    <td colspan="<?= count($columns) ?>" class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500 dark:text-gray-400">
                        <?= htmlspecialchars($emptyMessage) ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($data as $row): ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition duration-150">
                        <?php foreach ($columns as $column): ?>
                            <?php 
                                $value = $row[$column['key']] ?? '';
                                $type = $column['type'] ?? 'text';
                                $isWrap = !empty($column['wrap']);
                            ?>
                            <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100 <?= $isWrap ? 'whitespace-normal break-all' : 'whitespace-nowrap' ?>">
                                <?php if ($isWrap): ?><div class="line-clamp-2 overflow-hidden text-ellipsis break-all"><?php endif; ?>
                                
                                <?php if ($type === 'tracking_link'): ?>
                                    <span class="font-mono font-bold text-accent-1">
                                        <a href="/documents/<?= htmlspecialchars($value) ?>" class="hover:underline">
                                            <?= htmlspecialchars($value) ?>
                                        </a>
                                    </span>
                                <?php elseif ($type === 'status'): ?>
                                    <?php $status = $value; require BASE_PATH . '/src/Views/components/status-badge.php'; ?>
                                <?php elseif ($type === 'date'): ?>
                                    <span class="text-gray-500 dark:text-gray-400">
                                        <?= htmlspecialchars($value ? (new DateTime($value))->format('M d, Y h:i A') : 'N/A') ?>
                                    </span>
                                <?php elseif ($type === 'action_view'): ?>
                                    <div class="text-right">
                                        <a href="/documents/<?= htmlspecialchars($row['tracking_code'] ?? $value) ?>" class="text-accent-1 hover:text-accent-1-active">View</a>
                                    </div>
                                <?php elseif ($type === 'raw'): ?>
                                    <?= $value ?>
                                <?php else: ?>
                                    <span class="text-gray-500 dark:text-gray-400" title="<?= htmlspecialchars($value) ?>">
                                        <?= htmlspecialchars($value) ?>
                                    </span>
                                <?php endif; ?>

                                <?php if ($isWrap): ?></div><?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
