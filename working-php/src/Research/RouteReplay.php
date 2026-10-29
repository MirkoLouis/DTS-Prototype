<?php

namespace App\Research;

use App\Routing\HeuristicRouter;

/**
 * Replays the legacy DTS routing export through HeuristicRouter.
 * For every document it asks what the app would have suggested (the last finalized route
 * learned for the same purpose) and compares it with the route the Records Unit assigned.
 */
class RouteReplay
{
    /** Section names the legacy system used for the Records Unit (intake side). */
    public const DEFAULT_RECORDS_SECTIONS = [
        'Records Unit- Receiving',
        'OSDS - AS - Records Unit',
        'Records Unit',
    ];

    /** Section names that are Records Unit hand-backs and are dropped from the end of a route. */
    public const DEFAULT_TRAILING_SECTIONS = [
        'Records- Releasing',
    ];

    private HeuristicRouter $service;

    /**
     * @param list<string> $recordsSections  names that mark the Records Unit step (R)
     * @param list<string> $trailingSections extra names removed from the end of a route
     */
    public function __construct(
        private array $recordsSections = self::DEFAULT_RECORDS_SECTIONS,
        private array $trailingSections = self::DEFAULT_TRAILING_SECTIONS,
    ) {
        $this->service = new HeuristicRouter();
    }

    /**
     * Replay the rows (assoc arrays keyed by the legacy column names).
     *
     * @param iterable<array<string,string>>|callable():iterable<array<string,string>> $rows  a callable is invoked twice (two passes) so a CSV can be streamed
     * @return array{documents:int, excluded_no_prior:int, excluded_others:int, excluded_no_date:int, skipped_malformed_rows:int, rows_read:int, exact_matches:int, first_dept_matches:int, by_year:array, by_purpose:array}
     */
    public function run(iterable|callable $rows): array
    {
        // Two passes over the rows give R and the rows after it per document
        $result = [
            'documents' => 0, 'excluded_no_prior' => 0, 'excluded_others' => 0, 'excluded_no_date' => 0,
            'skipped_malformed_rows' => 0, 'rows_read' => 0,
            'exact_matches' => 0, 'first_dept_matches' => 0,
            'by_year' => [], 'by_purpose' => [],
        ];
        [$starts, $docs] = $this->scan($rows, $result);

        // Reduce each document to its Records step and assigned route
        $candidates = [];
        foreach ($starts as $id => $r) {
            $candidate = $this->extract(['r' => $r, 'rows' => $docs[$id] ?? []]);
            if ($candidate === null) {
                continue;
            }

            // Non-official purposes are never suggested or learned
            if ($candidate['key'] === '' || $candidate['key'] === 'others') {
                $result['excluded_others']++;
                continue;
            }

            // Without a usable date the document cannot be placed in the replay order
            if ($candidate['when'] === null) {
                $result['excluded_no_date']++;
                continue;
            }
            $candidates[] = $candidate;
        }
        unset($docs);

        // Replay chronologically so each suggestion only knows earlier documents
        usort($candidates, fn($a, $b) => [$a['when'], $a['action']] <=> [$b['when'], $b['action']]);
        $learned = [];
        foreach ($candidates as $c) {
            $result['documents']++;
            $key = $c['key'];
            $purpose = ['is_official' => 1, 'suggested_route' => json_encode($learned[$key] ?? [])];

            // Ask the app's own rule what it would have suggested
            $suggested = array_column($this->service->suggest($purpose, null), 'name');
            if ($suggested === []) {
                $result['excluded_no_prior']++;
            } else {
                $exact = $suggested === $c['route'];
                $first = $suggested[0] === $c['route'][0];
                $result['exact_matches'] += (int) $exact;
                $result['first_dept_matches'] += (int) $first;
                $this->tally($result['by_year'], $c['year'], $exact, $first);
                $this->tally($result['by_purpose'], $key, $exact, $first);
            }

            // Learn the finalized route the way the app does
            $learned[$key] = $this->service->learnedRoute($purpose, $c['route'], null) ?? $learned[$key] ?? $c['route'];
        }

        ksort($result['by_year']);
        ksort($result['by_purpose']);
        return $result;
    }

    /**
     * Assigned route per document id (documents that never qualify are absent).
     *
     * @return array<string,list<string>>
     */
    public function assignedRoutes(iterable|callable $rows): array
    {
        // Scan, then extract each document's route
        $counters = ['rows_read' => 0, 'skipped_malformed_rows' => 0];
        [$starts, $docs] = $this->scan($rows, $counters);
        $routes = [];
        foreach ($starts as $id => $r) {
            $candidate = $this->extract(['r' => $r, 'rows' => $docs[$id] ?? []]);
            if ($candidate !== null) {
                $routes[(string) $id] = $candidate['route'];
            }
        }

        return $routes;
    }

    /**
     * Two passes over the rows: R per document, then the rows after R.
     *
     * @return array{0:array,1:array}
     */
    private function scan(iterable|callable $rows, array &$result): array
    {
        // A callable yields a fresh stream per call; a plain iterable is buffered so it can be read twice
        if (!is_callable($rows)) {
            $buffer = $rows instanceof \Traversable ? iterator_to_array($rows, false) : $rows;
            $rows = fn() => $buffer;
        }
        // Pass 1: per document, find R (the Records row with the lowest action id; the first seen wins a tie)
        $starts = [];
        foreach ($rows() as $row) {
            // Rows the reader could not parse are counted, never silently dropped
            if (isset($row['__malformed'])) {
                $result['skipped_malformed_rows']++;
                continue;
            }
            $result['rows_read']++;
            $action = (int) $row['action_id'];
            if (!in_array(trim((string) $row['route_tosection']), $this->recordsSections, true)) {
                continue;
            }
            $id = $row['document_id'];
            if (!isset($starts[$id]) || $action < $starts[$id]['action']) {
                $starts[$id] = [
                    'action' => $action,
                    'purpose' => (string) ($row['route_purpose'] ?? ''),
                    'forwarded' => (string) ($row['datetime_forwarded'] ?? ''),
                    'accepted' => (string) ($row['datetime_route_accepted'] ?? ''),
                ];
            }
        }

        // Pass 2: keep only the rows after R, as compact "action<TAB>section" strings
        $docs = [];
        foreach ($rows() as $row) {
            if (isset($row['__malformed'])) {
                continue;
            }
            $id = $row['document_id'];
            $action = (int) $row['action_id'];
            if (isset($starts[$id]) && $action > $starts[$id]['action']) {
                $docs[$id][] = $action . "\t" . trim((string) $row['route_tosection']);
            }
        }

        return [$starts, $docs];
    }

    /**
     * Turn one document's state into its assigned route; null when it never qualifies.
     */
    private function extract(array $doc): ?array
    {
        // Without R there is nothing to replay
        $r = $doc['r'];
        if ($r === null) {
            return null;
        }

        // Order the rows after R by action id and collapse consecutive duplicates
        $steps = array_map(fn($x) => explode("\t", $x, 2), $doc['rows']);
        usort($steps, fn($a, $b) => (int) $a[0] <=> (int) $b[0]);
        $route = [];
        foreach ($steps as [, $to]) {
            if ($to !== '' && end($route) !== $to) {
                $route[] = $to;
            }
        }

        // Remove trailing Records hand-backs
        $drop = array_merge($this->recordsSections, $this->trailingSections);
        while ($route !== [] && in_array(end($route), $drop, true)) {
            array_pop($route);
        }
        if ($route === []) {
            return null;
        }

        // Replay time: acceptance, falling back to forwarding; null when neither is usable
        $when = null;
        foreach ([$r['accepted'], $r['forwarded']] as $candidate) {
            if ($candidate !== '' && !str_starts_with($candidate, '0000')) {
                $when = $candidate;
                break;
            }
        }

        return [
            'key' => strtolower(trim($r['purpose'])),
            'route' => $route,
            'when' => $when,
            'action' => $r['action'],
            'year' => $when === null ? '' : substr($when, 0, 4),
        ];
    }

    /**
     * Add one scored document to a bucket.
     */
    private function tally(array &$buckets, string $name, bool $exact, bool $first): void
    {
        // Create the bucket on first use, then count
        $buckets[$name] ??= ['documents' => 0, 'exact_matches' => 0, 'first_dept_matches' => 0];
        $buckets[$name]['documents']++;
        $buckets[$name]['exact_matches'] += (int) $exact;
        $buckets[$name]['first_dept_matches'] += (int) $first;
    }

    /**
     * Stream a legacy CSV as assoc rows without loading the file into memory.
     *
     * @return \Generator<array<string,string>>
     */
    public static function readCsv(string $path): \Generator
    {
        // Open the file and read the header once
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open $path");
        }
        $header = fgetcsv($handle, 0, ',', '"', '\\');

        // Yield one keyed row at a time
        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count($line) === count($header)) {
                yield array_combine($header, $line);
            } else {
                // Flag the row so run() can count it
                yield ['__malformed' => true];
            }
        }
        fclose($handle);
    }

    /**
     * Write the overall, by_year and by_purpose CSVs into $dir.
     */
    public static function writeResults(array $r, string $dir, array $config = []): void
    {
        // Create the output directory when missing
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // Record how this result set was produced
        $out = fopen($dir . '/route-replay-config.csv', 'w');
        fputcsv($out, ['key', 'value'], ',', '"', '\\');
        $config += [
            'rows_read' => $r['rows_read'], 'skipped_malformed_rows' => $r['skipped_malformed_rows'],
            'excluded_no_date' => $r['excluded_no_date'], 'run_at' => date('c'),
        ];
        foreach ($config as $k => $v) {
            fputcsv($out, [$k, is_array($v) ? implode(' | ', $v) : $v], ',', '"', '\\');
        }
        fclose($out);
        $pct = fn($n, $d) => $d > 0 ? round($n / $d * 100, 2) : 0;
        $scored = $r['documents'] - $r['excluded_no_prior'];

        // Overall summary
        $out = fopen($dir . '/route-replay-overall.csv', 'w');
        fputcsv($out, ['documents', 'excluded_others', 'excluded_no_prior', 'scored', 'exact_matches', 'exact_pct', 'first_dept_matches', 'first_dept_pct'], ',', '"', '\\');
        fputcsv($out, [$r['documents'], $r['excluded_others'], $r['excluded_no_prior'], $scored, $r['exact_matches'], $pct($r['exact_matches'], $scored), $r['first_dept_matches'], $pct($r['first_dept_matches'], $scored)], ',', '"', '\\');
        fclose($out);

        // Per-year and per-purpose breakdowns
        foreach (['by_year' => 'year', 'by_purpose' => 'purpose'] as $part => $label) {
            $out = fopen($dir . "/route-replay-$part.csv", 'w');
            fputcsv($out, [$label, 'scored', 'exact_matches', 'exact_pct', 'first_dept_matches', 'first_dept_pct'], ',', '"', '\\');
            foreach ($r[$part] as $name => $b) {
                fputcsv($out, [$name, $b['documents'], $b['exact_matches'], $pct($b['exact_matches'], $b['documents']), $b['first_dept_matches'], $pct($b['first_dept_matches'], $b['documents'])], ',', '"', '\\');
            }
            fclose($out);
        }
    }
}
