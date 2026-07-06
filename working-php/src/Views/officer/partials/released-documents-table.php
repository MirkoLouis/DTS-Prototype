<?php
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
    require BASE_PATH . '/src/Views/components/table.php';
?>

<div class="mt-4">
    <?php require BASE_PATH . '/src/Views/components/pagination.php'; ?>
</div>
