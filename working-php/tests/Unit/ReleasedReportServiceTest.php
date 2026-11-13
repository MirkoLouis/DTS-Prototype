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
}
