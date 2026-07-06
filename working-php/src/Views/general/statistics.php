<?php ob_start(); ?>
<h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
    <?= htmlspecialchars($_SESSION['department_name'] ?? 'Department') ?> Statistics
</h2>
<?php $header = ob_get_clean(); ?>

<?php ob_start(); ?>
<div class="py-0">
    <div class="mx-[20vh] sm:px-6 lg:px-8 space-y-6">
        
        <?php require BASE_PATH . '/src/Views/components/department-analytics.php'; ?>

        <?php if (($_SESSION['role'] ?? '') === 'officer'): ?>
        <div id="released-documents-section" data-fetch-url="/statistics">
            <?php
                $panelTitle = 'Released Documents History';
                
                $purposeOptions = [];
                foreach ($purposes as $p) {
                    $purposeOptions[$p['name']] = $p['name'];
                }

                $filterConfig = [
                    'date' => ['type' => 'date', 'label' => 'Date'],
                    'purpose' => [
                        'type' => 'select',
                        'label' => 'Purpose',
                        'options' => array_merge(['all' => 'All Purposes'], $purposeOptions)
                    ],
                    'submitter' => ['type' => 'text', 'label' => 'Submitter Name', 'placeholder' => 'Search submitter...'],
                    'search' => ['type' => 'text', 'label' => 'Search Tracking # / Title', 'placeholder' => 'Search...']
                ];

                foreach ($releasedDocuments as &$document) {
                    $document['submitter_html'] = sprintf('<span class="submitter-name">%s</span>', htmlspecialchars($document['guest_info']['name'] ?? 'N/A'));
                    $document['district_text'] = htmlspecialchars($document['district'] ?? 'N/A');
                }
                unset($document);

                $tableConfig = [
                    'wrapper_classes' => 'overflow-x-auto mt-4',
                    'columns' => [
                        ['key' => 'tracking_code', 'label' => 'Tracking Code', 'width' => 'w-[25%]', 'type' => 'tracking_link'],
                        ['key' => 'submitter_html', 'label' => 'Submitter', 'width' => 'w-[20%]', 'type' => 'raw'],
                        ['key' => 'purpose_name', 'label' => 'Purpose', 'width' => 'w-[20%]', 'wrap' => true],
                        ['key' => 'district_text', 'label' => 'District', 'width' => 'w-[15%]', 'wrap' => true],
                        ['key' => 'released_at', 'label' => 'Date Released', 'width' => 'w-[20%]', 'type' => 'date']
                    ],
                    'data' => $releasedDocuments,
                    'empty_message' => 'No released documents match your search.'
                ];

                require BASE_PATH . '/src/Views/components/data-panel.php';
            ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<script src="/js/statistics.js"></script>

<?php $content = ob_get_clean(); ?>

<?php require BASE_PATH . '/src/Views/layouts/app.php'; ?>
