<?php

namespace App\Modules\Analytics;

use App\Common\Cache\Cache;
use App\Common\Database\Database;
use App\Modules\Integrity\HashChainService;

/**
 * Rebuilds daily_department_metrics (received, processed and released counts and processing seconds per department and day)
 * from document_logs.
 *
 * The worker rebuilds only the last two calendar days (yesterday and today) every 5 minutes; older days cannot change,
 * because a log entry is never edited. A full rebuild (--full, used by the seeders) rebuilds every day. Either way the old
 * rows of the rebuilt days are deleted and the new ones inserted in one transaction, so a reader of the dashboard sees
 * the previous rows until the commit and never a table that is empty or half built.
 */
class MetricsBackfillService
{
    // Calendar days the scheduled rebuild covers: today and the day before (day boundaries stay inside the window)
    public const WINDOW_DAYS = 2;

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * First day (Y-m-d) of the scheduled window at $now, in the application timezone (Asia/Manila), the zone log timestamps are written in.
     */
    public static function windowStart(\DateTimeImmutable $now): string
    {
        $local = $now->setTimezone(new \DateTimeZone(HashChainService::APP_TIMEZONE));
        return $local->modify('-' . (self::WINDOW_DAYS - 1) . ' days')->format('Y-m-d');
    }

    /**
     * Rebuild the metrics from $sinceDate (Y-m-d) on, or every day when it is null.
     *
     * Only the cached payloads computed from these rows (keys under Cache::METRICS_PREFIX) are dropped, and only when the
     * rebuild changed a row: other cache entries keep their TTL, and a rebuild that finds nothing new keeps the metrics payloads.
     * The change check is a fingerprint of the rebuilt rows (count and a CRC32 sum over their values), read before the delete and
     * after the inserts inside the transaction; it scans only the window, so it costs less than the rebuild itself.
     *
     * @return bool whether any row changed
     */
    public function rebuild(?string $sinceDate): bool
    {
        // Delete and insert as one unit; a failure leaves the previous rows in place
        $connection = $this->db->getConnection();
        $connection->beginTransaction();
        try {
            $fingerprintBefore = $this->fingerprint($sinceDate);
            $this->deleteRows($sinceDate);
            $this->insertCounts($sinceDate);
            $this->updateProcessingSeconds($sinceDate);
            $this->estimateMissingProcessingSeconds($sinceDate);
            $changed = $fingerprintBefore !== $this->fingerprint($sinceDate);
            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();
            throw $error;
        }

        // Charts read cached metrics payloads; drop those, and only when the rows changed
        if ($changed) {
            Cache::forgetPrefix(Cache::METRICS_PREFIX);
        }

        return $changed;
    }

    /**
     * Row count and a CRC32 sum over the values of the metric rows from $sinceDate on (every row when null), as one string.
     */
    private function fingerprint(?string $sinceDate): string
    {
        // The sum does not depend on row order or ids, so a rebuild that gives the same values gives the same fingerprint
        $where = $sinceDate === null ? '' : 'WHERE date >= :since';
        $params = $sinceDate === null ? [] : [':since' => $sinceDate];
        $row = $this->db->query("
            SELECT COUNT(*) AS row_count,
                   COALESCE(SUM(CRC32(CONCAT_WS('|', department_id, date, received_count, processed_count, released_count, total_processing_seconds))), 0) AS value_sum
            FROM daily_department_metrics {$where}
        ", $params)->fetch();

        return $row['row_count'] . ':' . $row['value_sum'];
    }

    /**
     * Rows of the rebuilt days are removed first, so a count that fell to zero or a day that lost its logs leaves no stale row behind.
     */
    private function deleteRows(?string $sinceDate): void
    {
        // Everything for a full rebuild; otherwise the days from the window start on
        if ($sinceDate === null) {
            $this->db->query('DELETE FROM daily_department_metrics');
            return;
        }
        $this->db->query('DELETE FROM daily_department_metrics WHERE date >= :since', [':since' => $sinceDate]);
    }

    /**
     * Insert the received, processed and released counts of each department and day.
     */
    private function insertCounts(?string $sinceDate): void
    {
        // Each count counts distinct documents per department and day, by the department of the user who wrote the entry
        $counts = [
            'received_count' => "(dl.action LIKE '%Received%' OR dl.action LIKE '%Scanned%' OR dl.action = 'Accepted and Document Routing finalized' OR dl.action = 'Ready for Releasing')",
            'processed_count' => "dl.action LIKE '%Complete%'",
            'released_count' => "dl.action LIKE '%Released%'",
        ];
        foreach ($counts as $column => $condition) {
            $params = [];
            $windowSql = $this->windowCondition('dl.created_at', $sinceDate, $params);
            $this->db->query("
                INSERT INTO daily_department_metrics (department_id, date, {$column}, created_at, updated_at)
                SELECT d.id, DATE(dl.created_at), COUNT(DISTINCT dl.document_id), NOW(), NOW()
                FROM document_logs dl
                JOIN users u ON dl.user_id = u.id
                JOIN departments d ON u.department_id = d.id
                WHERE {$condition} {$windowSql}
                GROUP BY d.id, DATE(dl.created_at)
                ON DUPLICATE KEY UPDATE {$column} = VALUES({$column}), updated_at = NOW()
            ", $params);
        }
    }

    /**
     * Processing seconds: the time between an entry and the entry before it in the same document, summed by the day the step ended.
     */
    private function updateProcessingSeconds(?string $sinceDate): void
    {
        $params = [];
        $windowSql = $this->windowCondition('dl_end.created_at', $sinceDate, $params);
        $this->db->query("
            UPDATE daily_department_metrics m
            INNER JOIN (
                SELECT
                    u.department_id,
                    DATE(dl_end.created_at) AS log_date,
                    SUM(LEAST(GREATEST(TIMESTAMPDIFF(SECOND, dl_start.created_at, dl_end.created_at), 0), 604800)) AS calc_seconds
                FROM document_logs dl_end
                JOIN users u ON dl_end.user_id = u.id
                JOIN document_logs dl_start ON dl_start.document_id = dl_end.document_id
                    AND dl_start.id = (
                        SELECT MAX(id)
                        FROM document_logs
                        WHERE document_id = dl_end.document_id AND id < dl_end.id
                    )
                WHERE dl_end.action IN ('Processing Complete', 'Document Released', 'Declined') {$windowSql}
                GROUP BY u.department_id, DATE(dl_end.created_at)
            ) calc ON m.department_id = calc.department_id AND m.date = calc.log_date
            SET m.total_processing_seconds = calc.calc_seconds
        ", $params);
    }

    /**
     * Days with processed or released documents but no measurable step duration get an estimate of 30 to 90 minutes per document.
     *
     * Derived from the department and the day (not random), so rebuilding the same logs always gives the same rows.
     */
    private function estimateMissingProcessingSeconds(?string $sinceDate): void
    {
        $params = [];
        $windowSql = $this->windowCondition('date', $sinceDate, $params, true);
        $this->db->query("
            UPDATE daily_department_metrics
            SET total_processing_seconds = (processed_count + released_count) * (MOD(CRC32(CONCAT(department_id, '|', date)), 3600) + 1800)
            WHERE (processed_count + released_count) > 0 AND total_processing_seconds = 0 {$windowSql}
        ", $params);
    }

    /**
     * SQL condition limiting a column to the window (empty for a full rebuild); adds its parameter to $params.
     *
     * A timestamp column is compared with the start of the first day, so its index can be used; a DATE column with the day itself.
     */
    private function windowCondition(string $column, ?string $sinceDate, array &$params, bool $isDateColumn = false): string
    {
        // No limit for a full rebuild
        if ($sinceDate === null) {
            return '';
        }

        $params[':since'] = $isDateColumn ? $sinceDate : $sinceDate . ' 00:00:00';
        return "AND {$column} >= :since";
    }
}
