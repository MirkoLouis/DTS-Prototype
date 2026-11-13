<?php

namespace App\Modules\Analytics;

use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;

/**
 * Rules of the released-documents report, shared by the report request and the spreadsheet download.
 */
class ReleasedReportService
{
    /**
     * Build one spreadsheet row for a released document.
     *
     * Every value is written as a text cell. Row::fromValues() would turn a value that starts
     * with "=" into a live formula, and the title, district and submitter name are typed by guests
     * (spreadsheet formula injection).
     */
    public static function toRow(array $document): Row
    {
        // The submitter's name comes from the guest details stored with the document
        $guestInfo = json_decode((string) ($document['guest_info'] ?? ''), true);
        $guestName = is_array($guestInfo) && isset($guestInfo['name']) ? (string) $guestInfo['name'] : 'N/A';

        // Column order matches the header row: code, title, purpose, district, submitter, release time
        $values = [
            $document['tracking_code'] ?? '',
            $document['title'] ?? '',
            $document['purpose_name'] ?? '',
            $document['district'] ?? '',
            $guestName,
            $document['updated_at'] ?? '',
        ];

        return new Row(array_map(fn($value) => new StringCell((string) $value, null), $values));
    }
}
