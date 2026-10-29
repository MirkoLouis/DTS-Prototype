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

    /** Strategy keys reported side by side (static_hindsight looks ahead by design). */
    public const STRATEGIES = ['heuristic', 'none', 'frequent_so_far', 'static_hindsight'];

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

        // Whole-set route counts per purpose: feed static_hindsight and the purpose profile (order-independent)
        $whole = [];
        foreach ($candidates as $i => $c) {
            $sig = json_encode($c['route']);
            $whole[$c['key']][$sig] ??= ['n' => 0, 'last' => 0, 'route' => $c['route']];
            $whole[$c['key']][$sig]['n']++;
            $whole[$c['key']][$sig]['last'] = $i;
        }
        $hindsight = [];
        foreach ($whole as $key => $routes) {
            $hindsight[$key] = $this->mostCommon($routes);
        }

        // Empty tallies for every strategy
        $result['baselines'] = [];
        foreach (self::STRATEGIES as $name) {
            $result['baselines'][$name] = ['exact_matches' => 0, 'first_dept_matches' => 0, 'by_year' => [], 'by_purpose' => []];
        }
        $learned = [];
        $seen = [];
        foreach ($candidates as $i => $c) {
            $result['documents']++;
            $key = $c['key'];
            $purpose = ['is_official' => 1, 'suggested_route' => json_encode($learned[$key] ?? [])];

            // Ask the app's own rule what it would have suggested
            $suggested = array_column($this->service->suggest($purpose, null), 'name');
            if ($suggested === []) {
                $result['excluded_no_prior']++;
            } else {
                // Every strategy is scored on the same document; "none" suggests nothing
                $suggestions = [
                    'heuristic' => $suggested,
                    'none' => [],
                    'frequent_so_far' => isset($seen[$key]) ? $this->mostCommon($seen[$key]) : [],
                    'static_hindsight' => $hindsight[$key],
                ];
                foreach ($suggestions as $name => $route) {
                    $exact = $route !== [] && $route === $c['route'];
                    $first = $route !== [] && $route[0] === $c['route'][0];
                    $b = &$result['baselines'][$name];
                    $b['exact_matches'] += (int) $exact;
                    $b['first_dept_matches'] += (int) $first;
                    $this->tally($b['by_year'], $c['year'], $exact, $first);
                    $this->tally($b['by_purpose'], $key, $exact, $first);
                    unset($b);
                }
                $exact = $suggested === $c['route'];
                $first = $suggested[0] === $c['route'][0];
                $result['exact_matches'] += (int) $exact;
                $result['first_dept_matches'] += (int) $first;
                $this->tally($result['by_year'], $c['year'], $exact, $first);
                $this->tally($result['by_purpose'], $key, $exact, $first);
            }

            // Learn the finalized route the way the app does
            $learned[$key] = $this->service->learnedRoute($purpose, $c['route'], null) ?? $learned[$key] ?? $c['route'];

            // The online frequency baseline learns this document only after it is scored
            $sig = json_encode($c['route']);
            $seen[$key][$sig] ??= ['n' => 0, 'last' => 0, 'route' => $c['route']];
            $seen[$key][$sig]['n']++;
            $seen[$key][$sig]['last'] = $i;
        }
        $result['profile'] = $this->profile($whole);
        // Sort every breakdown by its name
        ksort($result['by_year']);
        ksort($result['by_purpose']);
        foreach ($result['baselines'] as &$b) {
            ksort($b['by_year']);
            ksort($b['by_purpose']);
        }
        unset($b);
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
     * The most common route of a count table; ties go to the most recently seen route.
     *
     * @param array<string,array{n:int,last:int,route:list<string>}> $routes
     * @return list<string>
     */
    private function mostCommon(array $routes): array
    {
        // Highest count wins, then the latest occurrence
        $best = null;
        foreach ($routes as $r) {
            if ($best === null || [$r['n'], $r['last']] > [$best['n'], $best['last']]) {
                $best = $r;
            }
        }
        return $best['route'] ?? [];
    }

    /**
     * Route-shape statistics per purpose plus an "all" row, from the whole-set route counts.
     *
     * @param array<string,array<string,array{n:int,last:int,route:list<string>}>> $whole
     * @return array<string,array{documents:int,distinct_routes:int,top_route_share_pct:float,single_hop_pct:float,revisit_pct:float}>
     */
    private function profile(array $whole): array
    {
        // Accumulate per purpose and for everything together
        $acc = [];
        $all = [];
        foreach ($whole as $key => $routes) {
            foreach ($routes as $sig => $r) {
                foreach ([$key, 'all'] as $slot) {
                    $acc[$slot] ??= ['documents' => 0, 'routes' => [], 'single' => 0, 'revisit' => 0];
                    $acc[$slot]['documents'] += $r['n'];
                    $acc[$slot]['routes'][$sig] = ($acc[$slot]['routes'][$sig] ?? 0) + $r['n'];
                    $acc[$slot]['single'] += count($r['route']) === 1 ? $r['n'] : 0;
                    $acc[$slot]['revisit'] += count($r['route']) !== count(array_unique($r['route'])) ? $r['n'] : 0;
                }
            }
        }

        // Turn counts into percentages
        ksort($acc);
        $out = [];
        foreach ($acc as $slot => $a) {
            $d = $a['documents'];
            $out[$slot] = [
                'documents' => $d,
                'distinct_routes' => count($a['routes']),
                'top_route_share_pct' => round(max($a['routes']) / $d * 100, 2),
                'single_hop_pct' => round($a['single'] / $d * 100, 2),
                'revisit_pct' => round($a['revisit'] / $d * 100, 2),
            ];
        }
        return $out;
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
     * Write the overall, by_year, by_purpose, baselines and purpose-profile CSVs into $dir.
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

        // Strategy comparison in long format
        $out = fopen($dir . '/route-replay-baselines.csv', 'w');
        fputcsv($out, ['scope', 'key', 'strategy', 'scored', 'exact_matches', 'exact_pct', 'first_dept_matches', 'first_dept_pct'], ',', '"', '\\');
        foreach ($r['baselines'] ?? [] as $name => $b) {
            // Overall row, then every year and purpose
            $rows = [['overall', 'all', $scored, $b['exact_matches'], $b['first_dept_matches']]];
            foreach (['year' => 'by_year', 'purpose' => 'by_purpose'] as $scope => $part) {
                foreach ($b[$part] as $k => $x) {
                    $rows[] = [$scope, $k, $x['documents'], $x['exact_matches'], $x['first_dept_matches']];
                }
            }
            foreach ($rows as [$scope, $k, $n, $e, $f]) {
                fputcsv($out, [$scope, $k, $name, $n, $e, $pct($e, $n), $f, $pct($f, $n)], ',', '"', '\\');
            }
        }
        fclose($out);

        // Route-shape profile per purpose
        $out = fopen($dir . '/route-replay-purpose-profile.csv', 'w');
        fputcsv($out, ['purpose', 'documents', 'distinct_routes', 'top_route_share_pct', 'single_hop_pct', 'revisit_pct'], ',', '"', '\\');
        foreach ($r['profile'] ?? [] as $name => $p) {
            fputcsv($out, [$name, $p['documents'], $p['distinct_routes'], $p['top_route_share_pct'], $p['single_hop_pct'], $p['revisit_pct']], ',', '"', '\\');
        }
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
