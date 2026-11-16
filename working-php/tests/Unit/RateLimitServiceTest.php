<?php

namespace Tests\Unit;

use App\Common\Config\Config;
use App\Common\Database\Database;
use App\Common\RateLimit\RateLimitService;
use Tests\TestCase;

/**
 * Rate limiting (fixed window per bucket and client): counting, window reset, independence, own connection, fail open.
 */
class RateLimitServiceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    // Previous error_log target, restored after the test
    private string|false $previousErrorLog = false;

    // Forget the log throttle of earlier tests
    protected function setUp(): void
    {
        parent::setUp();
        RateLimitService::resetLogThrottle();
    }

    // Restore the error_log target a test redirected
    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
            $this->previousErrorLog = false;
        }
        parent::tearDown();
    }

    // Send error_log lines to a file under .test/ and return its path
    private function captureErrorLog(): string
    {
        $path = Config::testPath('rate-limit-error.log');
        @mkdir(dirname($path), 0755, true);
        @unlink($path);
        $this->previousErrorLog = ini_set('error_log', $path);

        return $path;
    }

    // Number of rows in rate_limits
    private function rowCount(): int
    {
        return (int) Database::getInstance()->query('SELECT COUNT(*) c FROM rate_limits')->fetch()['c'];
    }

    // The Nth hit within the window is allowed and the N+1th is refused with the seconds until the window resets
    public function test_nth_hit_is_allowed_and_next_is_refused_with_seconds_until_reset(): void
    {
        $service = new RateLimitService();

        // Ten hits in the same second are all allowed
        for ($i = 1; $i <= 10; $i++) {
            $this->assertNull($service->hit('submit', '203.0.113.7', 10, 60, self::NOW), "hit {$i} must be allowed");
        }

        // The eleventh is refused; the window opened at NOW, so 60 s remain, and 45 s remain 15 s later
        $this->assertSame(60, $service->hit('submit', '203.0.113.7', 10, 60, self::NOW));
        $this->assertSame(45, $service->hit('submit', '203.0.113.7', 10, 60, self::NOW + 15));
    }

    // Once windowSeconds have passed the counter restarts, and the new window is counted from the first hit in it
    public function test_window_resets_after_window_seconds(): void
    {
        $service = new RateLimitService();
        for ($i = 0; $i < 3; $i++) {
            $service->hit('track', '203.0.113.7', 2, 60, self::NOW);
        }
        $this->assertNotNull($service->hit('track', '203.0.113.7', 2, 60, self::NOW + 59));

        // At NOW + 60 the window has passed: allowed again, and a new window runs until NOW + 120
        $this->assertNull($service->hit('track', '203.0.113.7', 2, 60, self::NOW + 60));
        $this->assertNull($service->hit('track', '203.0.113.7', 2, 60, self::NOW + 61));
        $this->assertSame(59, $service->hit('track', '203.0.113.7', 2, 60, self::NOW + 61));
    }

    // A limit on one bucket does not affect another bucket of the same client
    public function test_buckets_are_independent(): void
    {
        $service = new RateLimitService();
        $service->hit('pdf', '203.0.113.7', 1, 60, self::NOW);
        $this->assertNotNull($service->hit('pdf', '203.0.113.7', 1, 60, self::NOW));

        $this->assertNull($service->hit('track', '203.0.113.7', 1, 60, self::NOW));
    }

    // A limit reached by one client does not affect another client
    public function test_clients_are_independent(): void
    {
        $service = new RateLimitService();
        $service->hit('pdf', '203.0.113.7', 1, 60, self::NOW);
        $this->assertNotNull($service->hit('pdf', '203.0.113.7', 1, 60, self::NOW));

        $this->assertNull($service->hit('pdf', '203.0.113.8', 1, 60, self::NOW));
    }

    // The hit is counted on the limiter's own connection, so a rollback of the caller's transaction does not erase it
    public function test_hit_survives_rollback_of_the_main_connection(): void
    {
        $main = Database::getInstance()->getConnection();
        $service = new RateLimitService();

        // Count a hit inside a transaction of the main connection, then roll that transaction back
        $main->beginTransaction();
        $service->hit('submit', '203.0.113.7', 1, 60, self::NOW);
        $main->rollBack();

        // The row is still there, so the next hit is already over the limit
        $this->assertSame(1, $this->rowCount());
        $this->assertNotNull($service->hit('submit', '203.0.113.7', 1, 60, self::NOW));
    }

    // purgeExpired removes only the rows whose window has passed
    public function test_purge_expired_removes_only_expired_rows(): void
    {
        $service = new RateLimitService();
        $service->hit('old', '203.0.113.7', 5, 60, self::NOW);
        $service->hit('current', '203.0.113.7', 5, 60, self::NOW + 100);

        // At NOW + 100 the first window (ending NOW + 60) has passed, the second (ending NOW + 160) has not
        $this->assertSame(1, $service->purgeExpired(self::NOW + 100));
        $this->assertSame(1, $this->rowCount());
        $this->assertSame(0, $service->purgeExpired(self::NOW + 100));

        // A window ending exactly now counts as expired
        $this->assertSame(1, $service->purgeExpired(self::NOW + 160));
    }

    // The stored key is a SHA-256 digest of bucket and client; the raw IP address is stored nowhere
    public function test_stored_key_is_a_hash_and_not_the_raw_ip(): void
    {
        (new RateLimitService())->hit('submit', '203.0.113.7', 5, 60, self::NOW);

        $rows = Database::getInstance()->query('SELECT * FROM rate_limits')->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame(RateLimitService::key('submit', '203.0.113.7'), $rows[0]['rate_key']);
        $this->assertSame(hash('sha256', 'submit|203.0.113.7'), $rows[0]['rate_key']);
        $this->assertStringNotContainsString('203.0.113.7', implode('|', array_map('strval', $rows[0])));
    }

    // With the table missing (migration not run) the request is allowed and one line is logged
    public function test_missing_table_fails_open_with_one_log_line(): void
    {
        Database::getInstance()->getConnection()->exec('DROP TABLE rate_limits');
        $log = $this->captureErrorLog();

        $service = new RateLimitService();
        $this->assertNull($service->hit('submit', '203.0.113.7', 1, 60, self::NOW));

        // One line, naming the migration
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log))));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('rate_limits table missing', $lines[0]);
        $this->assertStringContainsString('master-migration.sql', $lines[0]);
    }

    // A flood of failing requests writes at most one log line per 60 seconds
    public function test_failure_log_is_limited_to_one_line_per_minute(): void
    {
        Database::getInstance()->getConnection()->exec('DROP TABLE rate_limits');
        $log = $this->captureErrorLog();

        // The injected clock stands in for time()
        $clock = self::NOW;
        $service = new RateLimitService(null, function () use (&$clock): int {
            return $clock;
        });
        $lines = fn(): int => count(array_filter(explode("\n", (string) file_get_contents($log))));

        // 50 failures within the same minute: one line
        for ($i = 0; $i < 50; $i++) {
            $this->assertNull($service->hit('submit', '203.0.113.7', 1, 60, self::NOW));
        }
        $this->assertSame(1, $lines());

        // Still inside the minute at +59 s; a new line at +60 s
        $clock = self::NOW + 59;
        $service->hit('submit', '203.0.113.7', 1, 60, self::NOW);
        $this->assertSame(1, $lines());
        $clock = self::NOW + 60;
        $service->hit('submit', '203.0.113.7', 1, 60, self::NOW);
        $this->assertSame(2, $lines());
    }

    // When the limiter's connection cannot be opened the request is allowed and a line is logged
    public function test_unreachable_database_fails_open_with_one_log_line(): void
    {
        $log = $this->captureErrorLog();

        // The connection factory fails the way a refused or unauthorised connection does
        $service = new RateLimitService(function (): \PDO {
            throw new \PDOException('SQLSTATE[HY000] [1045] Access denied for user (simulated)');
        });

        $this->assertNull($service->hit('submit', '203.0.113.7', 1, 60, self::NOW));
        $this->assertNull($service->hit('submit', '203.0.113.7', 1, 60, self::NOW));
        $this->assertSame(0, $service->purgeExpired(self::NOW));

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log))));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('rate limit check failed', $lines[0]);
    }

    // Any other database failure also lets the request through, with one log line
    public function test_other_query_failure_fails_open_with_one_log_line(): void
    {
        // A column the query needs is gone, so the statement fails with an error other than "table missing"
        Database::getInstance()->getConnection()->exec('ALTER TABLE rate_limits DROP COLUMN hits');
        $log = $this->captureErrorLog();

        $this->assertNull((new RateLimitService())->hit('submit', '203.0.113.7', 1, 60, self::NOW));

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log))));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('rate limit', strtolower($lines[0]));
    }

    // Purging a missing table is harmless for the worker: it reports zero rows
    public function test_purge_with_missing_table_returns_zero(): void
    {
        Database::getInstance()->getConnection()->exec('DROP TABLE rate_limits');
        $this->captureErrorLog();

        $this->assertSame(0, (new RateLimitService())->purgeExpired(self::NOW));
    }
}
