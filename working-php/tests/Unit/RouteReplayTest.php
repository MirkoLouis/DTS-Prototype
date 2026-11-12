<?php

namespace Tests\Unit;

use App\Research\RouteReplay;
use PHPUnit\Framework\TestCase;

class RouteReplayTest extends TestCase
{
    // Replay the small fixture
    private function replay(): array
    {
        return (new RouteReplay())->run(fn() => RouteReplay::readCsv(BASE_PATH . '/tests/Fixtures/legacy-routes-small.csv'));
    }

    // 3 official documents (routes A, A, B), 1 "others", 1 never routed through Records
    public function test_counts_on_small_fixture(): void
    {
        $r = $this->replay();
        $this->assertSame(3, $r['documents']);
        $this->assertSame(1, $r['excluded_others']);
        $this->assertSame(1, $r['excluded_no_prior']);
        $this->assertSame(1, $r['exact_matches']);
        $this->assertSame(1, $r['first_dept_matches']);
    }

    // Document 2 has Cash Unit twice in a row; it must still equal route A and match exactly
    public function test_consecutive_duplicate_sections_collapse(): void
    {
        $r = $this->replay();
        $this->assertSame(1, $r['exact_matches']);
        $this->assertSame(2, $r['by_year']['2022']['documents']);
    }

    // A document with no usable date is excluded and creates no empty year bucket; short rows are counted
    public function test_undated_documents_and_malformed_rows_are_reported(): void
    {
        $r = $this->replay();
        $this->assertSame(1, $r['excluded_no_date']);
        $this->assertSame(1, $r['skipped_malformed_rows']);
        $this->assertSame([2022], array_keys($r['by_year']));
    }

    // Rows in the order 3(Records), 2(Cash), 1(Records), 4(Budget) still give [Cash, Records, Budget]
    public function test_selection_is_independent_of_file_order(): void
    {
        $row = fn($action, $to) => [
            'action_id' => (string) $action, 'document_id' => '99', 'route_tosection' => $to,
            'route_purpose' => 'reporting/filing', 'datetime_forwarded' => '2022-06-01 08:00:00',
            'datetime_route_accepted' => '2022-06-01 08:10:00',
        ];
        $rows = [
            $row(3, 'Records Unit- Receiving'), $row(2, 'Cash Unit'),
            $row(1, 'Records Unit- Receiving'), $row(4, 'Budget Services'),
        ];
        // Shuffled and ascending orders must agree on the literal route
        $expected = ['Cash Unit', 'Records Unit- Receiving', 'Budget Services'];
        $replay = new RouteReplay();
        $this->assertSame(['99' => $expected], $replay->assignedRoutes($rows));
        $this->assertSame(['99' => $expected], $replay->assignedRoutes(array_reverse($rows)));
        $this->assertSame(['99' => $expected], $replay->assignedRoutes(fn() => $rows));
    }

    // Results go to the directory given, never the real results folder
    public function test_results_are_written_to_given_directory(): void
    {
        $dir = sys_get_temp_dir() . '/route-replay-' . uniqid();
        RouteReplay::writeResults($this->replay(), $dir);
        $this->assertFileExists($dir . '/route-replay-overall.csv');
        $this->assertFileExists($dir . '/route-replay-by_year.csv');
        $this->assertFileExists($dir . '/route-replay-by_purpose.csv');
        array_map('unlink', glob($dir . '/*.csv'));
        rmdir($dir);
    }

    // Build rows for documents given as [id, purpose, date, route of sections]
    private function docRows(array $docs): array
    {
        // Every document starts at Records, then visits its sections in order
        $rows = [];
        $action = 1;
        foreach ($docs as [$id, $purpose, $date, $route]) {
            foreach (array_merge(['Records Unit- Receiving'], $route) as $section) {
                $rows[] = [
                    'action_id' => (string) $action++, 'document_id' => (string) $id, 'route_tosection' => $section,
                    'route_purpose' => $purpose, 'datetime_forwarded' => "$date 08:00:00", 'datetime_route_accepted' => "$date 08:10:00",
                ];
            }
        }
        return $rows;
    }

    // Seven approval documents and one filing document, chronological; X=[Cash], Y=[Budget], Z=[Cash, Budget, Cash]
    private function baselineRows(): array
    {
        $p = 'for approval/signature';
        $x = ['Cash Unit'];
        $y = ['Budget Services'];
        $z = ['Cash Unit', 'Budget Services', 'Cash Unit'];
        return $this->docRows([
            [1, $p, '2022-01-01', $x], [2, $p, '2022-01-02', $y], [3, $p, '2022-01-03', $y], [4, $p, '2022-01-04', $x],
            [5, $p, '2022-01-05', $x], [6, $p, '2022-01-06', $z], [7, $p, '2023-01-01', $z],
            [8, 'reporting/filing', '2022-02-01', $x],
        ]);
    }

    // Hand-computed per-strategy matches (doc 1 and doc 8 have no prior route, so 6 are scored)
    public function test_strategies_are_scored_on_the_same_documents(): void
    {
        $r = (new RouteReplay())->run(fn() => $this->baselineRows());
        $this->assertSame(8, $r['documents']);
        $this->assertSame(2, $r['excluded_no_prior']);
        $b = $r['baselines'];

        // heuristic (last route): d2 X/Y miss, d3 Y hit, d4 Y/X miss, d5 X hit, d6 X/Z miss, d7 Z hit -> 3 exact; first dept: d3, d5, d6, d7 -> 4
        $this->assertSame([3, 4], [$b['heuristic']['exact_matches'], $b['heuristic']['first_dept_matches']]);
        $this->assertSame($r['exact_matches'], $b['heuristic']['exact_matches']);

        // none never suggests
        $this->assertSame([0, 0], [$b['none']['exact_matches'], $b['none']['first_dept_matches']]);

        // frequent_so_far: d2 X miss, d3 tie X1/Y1 -> latest Y hit, d4 Y2 miss, d5 tie X1... X2/Y2 -> latest X hit, d6 X3 vs Z miss (first hit), d7 X miss (first hit) -> 2 exact; first dept: d3, d5, d6, d7 -> 4
        $this->assertSame([2, 4], [$b['frequent_so_far']['exact_matches'], $b['frequent_so_far']['first_dept_matches']]);

        // static_hindsight: X is the most common (3 of 7) so d4, d5 hit exactly; first dept: d4, d5, d6, d7 -> 4
        $this->assertSame([2, 4], [$b['static_hindsight']['exact_matches'], $b['static_hindsight']['first_dept_matches']]);

        // Breakdowns use the same scored documents
        $this->assertSame(5, $b['frequent_so_far']['by_year']['2022']['documents']);
        $this->assertSame(1, $b['frequent_so_far']['by_year']['2023']['documents']);
        $this->assertSame(1, $b['heuristic']['by_year']['2023']['exact_matches']);
        $this->assertSame(0, $b['static_hindsight']['by_year']['2023']['exact_matches']);
        $this->assertSame(6, $b['none']['by_purpose']['for approval/signature']['documents']);
        $this->assertArrayNotHasKey('reporting/filing', $b['none']['by_purpose']);
    }

    // The frequent_so_far tie at d3 must go to the most recent route (Y), not the first seen (X)
    public function test_frequent_so_far_breaks_ties_by_recency(): void
    {
        $p = 'for approval/signature';
        $rows = $this->docRows([
            [1, $p, '2022-01-01', ['Cash Unit']], [2, $p, '2022-01-02', ['Budget Services']], [3, $p, '2022-01-03', ['Budget Services']],
        ]);
        $r = (new RouteReplay())->run($rows);
        $this->assertSame(1, $r['baselines']['frequent_so_far']['exact_matches']);
    }

    // Route-shape profile: purpose rows and the all row, hand computed
    public function test_purpose_profile(): void
    {
        $r = (new RouteReplay())->run(fn() => $this->baselineRows());
        $p = $r['profile'];
        $this->assertSame(['all', 'for approval/signature', 'reporting/filing'], array_keys($p));

        // 7 approval docs: X x3, Y x2, Z x2 -> top 3/7, single hop 5/7, revisit 2/7
        $this->assertSame(['documents' => 7, 'distinct_routes' => 3, 'top_route_share_pct' => 42.86, 'single_hop_pct' => 71.43, 'revisit_pct' => 28.57], $p['for approval/signature']);
        $this->assertSame(['documents' => 1, 'distinct_routes' => 1, 'top_route_share_pct' => 100.0, 'single_hop_pct' => 100.0, 'revisit_pct' => 0.0], $p['reporting/filing']);

        // All: X x4 of 8, single 6/8, revisit 2/8, still 3 distinct routes
        $this->assertSame(['documents' => 8, 'distinct_routes' => 3, 'top_route_share_pct' => 50.0, 'single_hop_pct' => 75.0, 'revisit_pct' => 25.0], $p['all']);
    }

    // The long baselines CSV and the profile CSV are written with the specified columns
    public function test_baselines_and_profile_csvs(): void
    {
        $dir = sys_get_temp_dir() . '/route-replay-' . uniqid();
        RouteReplay::writeResults((new RouteReplay())->run(fn() => $this->baselineRows()), $dir);
        $lines = array_map(fn($l) => str_getcsv($l, ',', '"', '\\'), file($dir . '/route-replay-baselines.csv', FILE_IGNORE_NEW_LINES));
        $this->assertSame(['scope', 'key', 'strategy', 'scored', 'exact_matches', 'exact_pct', 'first_dept_matches', 'first_dept_pct'], $lines[0]);
        $this->assertSame(['overall', 'all', 'heuristic', '6', '3', '50', '4', '66.67'], $lines[1]);
        $profile = file($dir . '/route-replay-purpose-profile.csv', FILE_IGNORE_NEW_LINES);
        $this->assertSame('purpose,documents,distinct_routes,top_route_share_pct,single_hop_pct,revisit_pct', $profile[0]);
        $this->assertSame('all,8,3,50,75,25', $profile[1]);
        array_map('unlink', glob($dir . '/*.csv'));
        rmdir($dir);
    }
}
