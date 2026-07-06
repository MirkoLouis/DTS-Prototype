<?php ob_start(); ?>
<h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
    Document Details: <?php echo htmlspecialchars($document['tracking_code']); ?>
</h2>
<?php $header = ob_get_clean(); ?>

<?php ob_start(); ?>
<div class="py-2">
    <div class="mx-[20vh] sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Document Information -->
                    <div>
                        <h3 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Document Information</h3>
                        <div class="space-y-4">
                            <p><strong>Tracking Code:</strong> <?php echo htmlspecialchars($document['tracking_code']); ?></p>
                            <?php 
                            $guestInfo = json_decode($document['guest_info'], true);
                            ?>
                            <p><strong>Submitter Name:</strong> <?php echo htmlspecialchars($guestInfo['name'] ?? 'N/A'); ?></p>
                            <p><strong>Submitter Email:</strong> <?php echo htmlspecialchars($guestInfo['email'] ?? 'N/A'); ?></p>
                            <p><strong>Purpose:</strong> <?php echo htmlspecialchars($document['purpose_name'] ?? 'Unknown'); ?></p>
                            <p><strong>Status:</strong> 
                                <?php $status = $document['status']; require BASE_PATH . '/src/Views/components/status-badge.php'; ?>
                            </p>
                            <p><strong>Submitted At:</strong> <?php echo date('M d, Y h:i A', strtotime($document['created_at'])); ?></p>
                        </div>
                    </div>
                    <!-- Routing Information -->
                    <div>
                        <h3 class="text-lg font-bold mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">Routing</h3>
                        
                        <p class="mb-2"><strong>Status:</strong> 
                                <?php $status = $document['status']; require BASE_PATH . '/src/Views/components/status-badge.php'; ?>
                        </p>

                        <?php if ($document['status'] == 'declined'): ?>
                            <p class="mt-2 text-red-600"><strong>Reason:</strong> Document declined.</p>
                        <?php elseif ($document['status'] == 'pending'): ?>
                            <p class="mt-2 text-gray-500 dark:text-gray-400">The route will be finalized upon intake.</p>
                        <?php else: ?>
                            <h4 class="font-semibold text-md text-gray-700 dark:text-gray-300 mt-4">Document Path:</h4>
                            <div class="mt-2">
                                <?php 
                                $route = $document['finalized_route'] ? json_decode($document['finalized_route'], true) : []; 
                                $currentStep = $document['current_step'] ?? 1;
                                ?>
                                <ul class="space-y-3">
                                    <!-- Step 1: Intake -->
                                    <li class="flex items-center justify-between text-gray-700 dark:text-gray-300">
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono text-gray-500 w-5">1.</span>
                                            <span>Intake</span>
                                        </div>
                                        <div>
                                            <?php $status = 'completed'; require BASE_PATH . '/src/Views/components/status-badge.php'; ?>
                                        </div>
                                    </li>

                                    <?php foreach ($route as $index => $step): ?>
                                        <li class="flex items-center justify-between <?php echo ($index + 1) === $currentStep ? 'font-bold text-gray-900 dark:text-gray-100' : 'text-gray-700 dark:text-gray-300'; ?>">
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono text-gray-500 font-normal w-5"><?= $index + 2 ?>.</span>
                                                <span><?php echo htmlspecialchars($step['name']); ?></span>
                                            </div>
                                            <div>
                                                <?php if (($index + 1) < $currentStep): ?>
                                                    <?php $status = 'completed'; require BASE_PATH . '/src/Views/components/status-badge.php'; ?>
                                                <?php elseif (($index + 1) === $currentStep): ?>
                                                    <?php $status = $document['status']; require BASE_PATH . '/src/Views/components/status-badge.php'; ?>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>

                                    <li class="flex items-center justify-between <?php echo $currentStep > count($route) ? 'font-bold text-gray-900 dark:text-gray-100' : 'text-gray-700 dark:text-gray-300'; ?>">
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono text-gray-500 font-normal w-5"><?= count($route) + 2 ?>.</span>
                                            <span>Records Unit (Releasing)</span>
                                        </div>
                                        <div>
                                            <?php if ($document['status'] === 'completed'): ?>
                                                <?php $status = 'completed'; require BASE_PATH . '/src/Views/components/status-badge.php'; ?>
                                            <?php elseif ($currentStep > count($route)): ?>
                                                <?php $status = $document['status']; require BASE_PATH . '/src/Views/components/status-badge.php'; ?>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Document Logs -->
                <div class="mt-8">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-2xl font-bold">Document History</h3>
                        <div class="flex items-center space-x-2">
                            <a href="/documents/<?php echo $document['tracking_code']; ?>/hash-chain" class="inline-flex items-center px-4 py-2 bg-accent-1 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-accent-1-hover active:bg-accent-1-active focus:outline-none focus:ring-2 focus:ring-accent-1 transition ease-in-out duration-150">
                                View Hash Chain
                            </a>
                            <a href="javascript:history.back()" class="inline-flex items-center px-4 py-2 bg-accent-2 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-accent-2-hover active:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-accent-2 transition ease-in-out duration-150">
                                Back
                            </a>
                        </div>
                    </div>
                    <?php
                        foreach ($logs as &$log) {
                            $log['user_name_text'] = htmlspecialchars($log['user_name'] ?? 'System');
                        }
                        unset($log);

                        $tableConfig = [
                            'wrapper_classes' => 'overflow-x-auto',
                            'columns' => [
                                ['key' => 'created_at', 'label' => 'Timestamp', 'width' => 'w-[20%]', 'type' => 'date'],
                                ['key' => 'action', 'label' => 'Action', 'width' => 'w-[25%]', 'wrap' => true],
                                ['key' => 'user_name_text', 'label' => 'Performed By', 'width' => 'w-[20%]'],
                                ['key' => 'remarks', 'label' => 'Remarks', 'width' => 'w-[35%]', 'wrap' => true]
                            ],
                            'data' => $logs,
                            'empty_message' => 'No history found.'
                        ];
                        require BASE_PATH . '/src/Views/components/table.php';
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); ?>

<?php require BASE_PATH . '/src/Views/layouts/app.php'; ?>
