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

    // Filters a report can be requested with
    private const FILTER_KEYS = ['date', 'purpose', 'submitter', 'search'];

    // Longest filter value kept
    private const MAX_FILTER_LENGTH = 190;

    /**
     * Reduce submitted form fields to the report's filters.
     *
     * The request also carries the CSRF token (and anything else a client adds); only the known
     * filters are stored with the report job, as trimmed text, and the date must be a real Y-m-d date.
     *
     * @return array<string, string>
     */
    public static function sanitizeFilters(array $input): array
    {
        $filters = [];

        // Keep each known filter that is non-empty text
        foreach (self::FILTER_KEYS as $key) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $filters[$key] = mb_substr(trim($value), 0, self::MAX_FILTER_LENGTH);
        }

        // A date that is not a real calendar day would make the report query fail
        if (isset($filters['date'])) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $filters['date']);
            if ($parsed === false || $parsed->format('Y-m-d') !== $filters['date']) {
                unset($filters['date']);
            }
        }

        return $filters;
    }
}
