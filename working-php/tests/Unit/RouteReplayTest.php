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
}
