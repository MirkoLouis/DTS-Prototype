<?php

namespace Tests\Unit;

use App\Modules\Analytics\ReleasedReportService;
use OpenSpout\Common\Entity\Cell\StringCell;
use PHPUnit\Framework\TestCase;

/**
 * Rules of the released-documents report: rows are text only, and stored filters are whitelisted.
 */
class ReleasedReportServiceTest extends TestCase
{
    // Values of a row's cells, after asserting each one is a text cell
    private function textValues(array $document): array
    {
        $values = [];
        foreach (ReleasedReportService::toRow($document)->cells as $cell) {
            $this->assertInstanceOf(StringCell::class, $cell);
            $values[] = $cell->getValue();
        }

        return $values;
    }

    // Guest-entered text that looks like a formula stays text in every column
    public function test_formula_like_values_are_string_cells(): void
    {
        $values = $this->textValues([
            'tracking_code' => 'DEPED-0000000001',
            'title' => '=HYPERLINK("http://x","y")',
            'purpose_name' => '-2+3',
            'district' => '+1+1',
            'guest_info' => json_encode(['name' => '@SUM(A1)']),
            'updated_at' => '2026-03-01 08:15:30',
        ]);

        $this->assertSame(['DEPED-0000000001', '=HYPERLINK("http://x","y")', '-2+3', '+1+1', '@SUM(A1)', '2026-03-01 08:15:30'], $values);
    }

    // Missing values become empty text, and a missing submitter name becomes N/A
    public function test_missing_values_become_empty_text(): void
    {
        $values = $this->textValues([
            'tracking_code' => 'DEPED-0000000002',
            'title' => null,
            'purpose_name' => null,
            'district' => null,
            'guest_info' => null,
            'updated_at' => null,
        ]);

        $this->assertSame(['DEPED-0000000002', '', '', '', 'N/A', ''], $values);
    }

    // Only the four report filters survive, as trimmed strings of at most 190 characters with a real date
    public function test_sanitize_filters_keeps_only_known_keys(): void
    {
        $filters = ReleasedReportService::sanitizeFilters([
            'csrf_token' => 'abc123',
            'pin' => '123456',
            'date' => 'garbage',
            'purpose' => '  Request for Service Record ',
            'submitter' => ['array', 'value'],
            'search' => str_repeat('x', 300),
        ]);

        $this->assertSame(['purpose' => 'Request for Service Record', 'search' => str_repeat('x', 190)], $filters);
    }

    // A real date is kept; no input gives no filters
    public function test_sanitize_filters_keeps_a_real_date_and_accepts_no_input(): void
    {
        $this->assertSame(['date' => '2026-03-05'], ReleasedReportService::sanitizeFilters(['date' => '2026-03-05']));
        $this->assertSame([], ReleasedReportService::sanitizeFilters(['date' => '2026-02-30']));
        $this->assertSame([], ReleasedReportService::sanitizeFilters([]));
    }
}
