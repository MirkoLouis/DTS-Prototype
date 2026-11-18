<?php

namespace Tests\Workflow;

use App\Common\Database\Database;
use App\Modules\Notifications\NotificationService;
use App\Modules\System\QueueService;
use App\Modules\Users\DepartmentResolver;
use Tests\TestCase;

/**
 * The indexes added in S11 are used by the queries they were added for (EXPLAIN names them as the key),
 * on tables large enough that MySQL would not prefer a full scan.
 */
class IndexUsageTest extends TestCase
{
    // Rows in each large table; the cost-based optimizer scans a table of a few rows
    private const ROWS = 20000;

    // Insert $rows rows with a number n from 1 to $rows into $table, from a SELECT list over n
    private function fill(string $table, string $columns, string $selectList, int $rows = self::ROWS): void
    {
        $connection = Database::getInstance()->getConnection();
        $connection->exec('SET SESSION cte_max_recursion_depth = ' . ($rows + 10));
        // Bulk rows refer to owners that need not exist, so foreign keys are not checked for the load
        $connection->exec('SET FOREIGN_KEY_CHECKS = 0');
        $connection->exec("INSERT INTO {$table} ({$columns}) WITH RECURSIVE seq (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < {$rows}) SELECT {$selectList} FROM seq");
        $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
        $connection->query("ANALYZE TABLE {$table}")->fetchAll();
    }

    // The EXPLAIN rows of a statement
    private function explain(string $sql, array $params = []): array
    {
        return Database::getInstance()->query('EXPLAIN ' . $sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    // The keys the plan uses, over every table in it
    private function keys(array $plan): array
    {
        return array_filter(array_column($plan, 'key'));
    }

    // The unread poll reads a department's newest unread notifications from notifications(department_id, is_read, created_at), without sorting
    public function test_notification_poll_uses_the_department_index(): void
    {
        // Notifications of 15 departments and 500 users, 80 % read, spread over time
        $this->fill('notifications', 'department_id, user_id, title, message, is_read, created_at', "IF(n % 2, 1 + (n % 15), NULL), IF(n % 2, NULL, 1 + (n % 500)), 't', 'm', IF(n % 10 < 8, 1, 0), NOW() - INTERVAL n SECOND");
        $departmentId = reset($this->fx['departments']);

        // The department branch, as the service builds it: the index, read backwards, no filesort
        $branch = $this->explain(NotificationService::unreadBranchSql(true), [':dept_id' => $departmentId]);
        $this->assertSame('idx_notifications_dept_unread', $branch[0]['key']);
        $this->assertStringNotContainsString('filesort', (string) $branch[0]['Extra']);

        // In the whole poll statement the UNION member, which is the department branch, reads that index
        $plan = $this->explain(NotificationService::unreadSql(true), [':user_id' => 1, ':dept_id' => $departmentId]);
        $unionMembers = array_values(array_filter($plan, fn($row) => $row['select_type'] === 'UNION'));
        $this->assertCount(1, $unionMembers);
        $this->assertSame('idx_notifications_dept_unread', $unionMembers[0]['key']);
    }

    // The poll returns the newest unread notifications of the user and of the user's department, newest first, at most 10
    public function test_notification_poll_returns_user_and_department_notifications(): void
    {
        $service = new NotificationService();
        $departmentId = $this->fx['departments']['Records Unit'];
        $userId = $this->fx['users']['records.unit'];
        $otherDepartment = $this->fx['departments']['Cash Unit'];
        $db = Database::getInstance();

        // Twelve department notifications, two of the user's own, one read, one for another department
        for ($i = 1; $i <= 12; $i++) {
            $db->query('INSERT INTO notifications (department_id, title, message, created_at) VALUES (:d, :t, \'m\', NOW() - INTERVAL :s SECOND)', [':d' => $departmentId, ':t' => "dept {$i}", ':s' => 100 + $i]);
        }
        $db->query('INSERT INTO notifications (user_id, title, message, created_at) VALUES (:u, \'mine newest\', \'m\', NOW() - INTERVAL 1 SECOND)', [':u' => $userId]);
        $db->query('INSERT INTO notifications (user_id, title, message, created_at) VALUES (:u, \'mine oldest\', \'m\', NOW() - INTERVAL 1000 SECOND)', [':u' => $userId]);
        $db->query('INSERT INTO notifications (user_id, title, message, is_read, created_at) VALUES (:u, \'read\', \'m\', 1, NOW())', [':u' => $userId]);
        $db->query('INSERT INTO notifications (department_id, title, message, created_at) VALUES (:d, \'other department\', \'m\', NOW())', [':d' => $otherDepartment]);

        // With a department: own newest first, then department notifications by age; the oldest of the user's are cut by the limit of 10
        $_SESSION = ['user_id' => $userId, 'department_id' => $departmentId];
        $titles = array_column($service->getUnreadForCurrentUser(), 'title');
        $this->assertSame(['mine newest', 'dept 1', 'dept 2', 'dept 3', 'dept 4', 'dept 5', 'dept 6', 'dept 7', 'dept 8', 'dept 9'], $titles);

        // Without a department only the user's own unread notifications
        $_SESSION = ['user_id' => $userId];
        $this->assertSame(['mine newest', 'mine oldest'], array_column($service->getUnreadForCurrentUser(), 'title'));
        $_SESSION = [];
    }

    // The job claim reads the next due job from jobs(reserved_at, available_at), one row, no filesort
    public function test_job_claim_uses_the_claim_index(): void
    {
        // 20000 reserved (running) jobs and 10 due, unreserved ones
        $this->fill('jobs', 'queue, payload, attempts, reserved_at, available_at, created_at', "'default', '{}', 1, IF(n <= " . self::ROWS . ", 1700000000, NULL), 1700000000 + n, 1700000000", self::ROWS + 10);

        $plan = $this->explain(QueueService::NEXT_PENDING_SQL);
        $this->assertSame('idx_jobs_claim', $plan[0]['key']);
        $this->assertStringNotContainsString('filesort', (string) $plan[0]['Extra']);
        $this->assertLessThanOrEqual(20, (int) $plan[0]['rows']);
    }

    // The claim returns the due job with the earliest availability, then the lowest id, and none while only future or reserved jobs exist
    public function test_next_pending_returns_the_oldest_due_job(): void
    {
        $queue = new QueueService();
        $db = Database::getInstance();
        $this->assertNull($queue->nextPending());

        // A reserved job and a future job never qualify; of two due jobs the earlier available one wins, ties by id
        $insert = 'INSERT INTO jobs (queue, payload, attempts, reserved_at, available_at, created_at) VALUES (\'default\', :p, 0, :r, UNIX_TIMESTAMP() + :a, 1)';
        $db->query($insert, [':p' => 'reserved', ':r' => 1, ':a' => -100]);
        $db->query($insert, [':p' => 'future', ':r' => null, ':a' => 3600]);
        $db->query($insert, [':p' => 'later', ':r' => null, ':a' => -10]);
        $db->query($insert, [':p' => 'earlier first id', ':r' => null, ':a' => -50]);
        $db->query($insert, [':p' => 'earlier second id', ':r' => null, ':a' => -50]);

        $this->assertSame('earlier first id', $queue->nextPending()['payload']);
    }

    // The cache purge deletes by expiration through cache(expiration)
    public function test_cache_purge_uses_the_expiration_index(): void
    {
        $this->fill('cache', '`key`, `value`, `expiration`', "CONCAT('k', n), '{}', 1700000000 + n");

        $plan = $this->explain('DELETE FROM cache WHERE expiration < 1700000100');
        $this->assertSame('idx_cache_expiration', $plan[0]['key']);
        $this->assertSame('range', $plan[0]['type']);
    }

    // A lookup of a department by name reads one row through the UNIQUE index, and a second department of the same name is refused
    public function test_department_name_lookup_uses_the_unique_index(): void
    {
        $lookup = $this->explain('SELECT id FROM departments WHERE name = :n', [':n' => 'Records Unit']);
        $this->assertSame('uq_departments_name', $lookup[0]['key']);
        $this->assertSame('const', $lookup[0]['type']);

        // The UNIQUE index refuses a second department of the same name
        $this->expectException(\PDOException::class);
        Database::getInstance()->query("INSERT INTO departments (name) VALUES ('Records Unit')");
    }

    // The purpose lookup by name (used for free-text purposes) and the name-ordered purpose list use purposes(name)
    public function test_purpose_name_queries_use_the_name_index(): void
    {
        // Enough purposes that a scan is not cheaper than an index lookup
        $this->fill('purposes', 'name, is_official', "CONCAT('Purpose ', n), 0", 500);

        $lookup = $this->explain('SELECT id FROM purposes WHERE name = :n AND is_official = 0', [':n' => 'Purpose 7']);
        $this->assertSame('idx_purposes_name', $lookup[0]['key']);

        $list = $this->explain('SELECT id, name FROM purposes ORDER BY name ASC');
        $this->assertSame('idx_purposes_name', $list[0]['key']);
    }

    // The legacy import merges a department name that differs only in case into the existing department, and logs the merge
    public function test_legacy_import_merges_departments_that_differ_in_case_and_logs_it(): void
    {
        $connection = Database::getInstance()->getConnection();
        $existingId = $this->fx['departments']['Records Unit'];
        $cache = [];
        $messages = [];
        $log = function (string $message) use (&$messages) {
            $messages[] = $message;
        };

        // A different spelling resolves to the existing id, is logged once, and adds no row
        $before = (int) $connection->query('SELECT COUNT(*) FROM departments')->fetchColumn();
        $this->assertSame($existingId, DepartmentResolver::getOrCreate($connection, 'RECORDS UNIT', $cache, $log));
        $this->assertSame($existingId, DepartmentResolver::getOrCreate($connection, 'RECORDS UNIT', $cache, $log));
        $this->assertSame(1, count($messages));
        $this->assertStringContainsString("'RECORDS UNIT' merged into existing department 'Records Unit'", $messages[0]);
        $this->assertSame($before, (int) $connection->query('SELECT COUNT(*) FROM departments')->fetchColumn());

        // A new name is created without a message
        $newId = DepartmentResolver::getOrCreate($connection, 'Brand New Unit', $cache, $log);
        $this->assertNotSame($existingId, $newId);
        $this->assertSame(1, count($messages));
        $this->assertSame($before + 1, (int) $connection->query('SELECT COUNT(*) FROM departments')->fetchColumn());
    }
}
