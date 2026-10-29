<?php

// Usage: php scripts/route-replay.php "<dts_docroutes CSV>" [output dir] [--records-names="A|B"]
define('BASE_PATH', dirname(__DIR__));
require __DIR__ . '/../vendor/autoload.php';

use App\Research\ResultWriter;
use App\Research\RouteReplay;

// Peak use is about 125 MB; keep headroom when the CLI limit is the 128M default
if (($limit = ini_get('memory_limit')) !== '-1' && (int) $limit < 256) {
    ini_set('memory_limit', '256M');
}

// Split options from positional arguments
$args = [];
$names = RouteReplay::DEFAULT_RECORDS_SECTIONS;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--records-names=')) {
        $names = explode('|', substr($arg, 16));
    } else {
        $args[] = $arg;
    }
}
if (empty($args[0]) || !is_file($args[0])) {
    fwrite(STDERR, "Usage: php scripts/route-replay.php <csv> [output dir] [--records-names=\"A|B\"]\n");
    exit(1);
}
// An explicit output directory wins; otherwise this run gets its own results folder
$startedAt = date('c');
$outDir = $args[1] ?? ResultWriter::dir();

// Replay the export and write the result files
$result = (new RouteReplay($names))->run(fn() => RouteReplay::readCsv($args[0]));
RouteReplay::writeResults($result, $outDir, [
    'records_sections' => $names,
    'trailing_sections' => RouteReplay::DEFAULT_TRAILING_SECTIONS,
    'input' => realpath($args[0]),
    'peak_memory_bytes' => memory_get_peak_usage(true),
]);

// Print the summary
$scored = $result['documents'] - $result['excluded_no_prior'];
printf("Records names:        %s\n", implode(' | ', $names));
printf("Qualifying documents: %d\n", $result['documents']);
printf("Excluded (others):    %d\n", $result['excluded_others']);
printf("Excluded (no date):    %d\n", $result['excluded_no_date']);
printf("Malformed rows skipped: %d\n", $result['skipped_malformed_rows']);
printf("Rows read:            %d\n", $result['rows_read']);
printf("Peak memory:          %.1f MB\n", memory_get_peak_usage(true) / 1048576);
printf("Excluded (no prior):  %d\n", $result['excluded_no_prior']);
printf("Scored:               %d\n", $scored);
printf("Exact match:          %d (%.2f%%)\n", $result['exact_matches'], $scored ? $result['exact_matches'] / $scored * 100 : 0);
printf("First-dept match:     %d (%.2f%%)\n", $result['first_dept_matches'], $scored ? $result['first_dept_matches'] / $scored * 100 : 0);
// One line per strategy (static_hindsight looks ahead by design)
foreach ($result['baselines'] as $name => $b) {
    printf("%-18s exact %d (%.2f%%), first-dept %d (%.2f%%)%s\n", $name . ':', $b['exact_matches'], $scored ? $b['exact_matches'] / $scored * 100 : 0, $b['first_dept_matches'], $scored ? $b['first_dept_matches'] / $scored * 100 : 0, $name === 'static_hindsight' ? '  [uses look-ahead]' : '');
}
ResultWriter::writeRunJson(['script' => 'route-replay', 'started_at' => $startedAt, 'input' => realpath($args[0])], $outDir);
echo "Output: $outDir\n";
