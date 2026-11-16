<?php

namespace App\Common\RateLimit;

use App\Common\Database\Database;
use PDO;

/**
 * Fixed-window rate limiting per (bucket, client).
 *
 * A window opens with a client's first hit in a bucket and lasts windowSeconds; at most $max hits fit in it. Each hit
 * is one atomic INSERT ... ON DUPLICATE KEY UPDATE, so simultaneous requests cannot exceed the limit. The counter lives
 * in rate_limits under SHA-256(bucket|client), so no IP address is stored. It is written on the service's own database
 * connection: a hit stays counted when the request's main connection later rolls back a transaction.
 *
 * Fails open: a missing table or a failing query lets the request through and writes one log line.
 */
class RateLimitService
{
    // Database error code for "table does not exist"
    private const TABLE_MISSING = '42S02';

    // At most one failure line per this many seconds per process, so a flood cannot fill the log
    private const LOG_INTERVAL_SECONDS = 60;

    // Time of the last failure line written by this process (shared by all instances)
    private static ?int $lastLoggedAt = null;

    // Lazily opened connection that is independent of the request's transactions
    private ?PDO $connection = null;

    // Opens that connection; replaceable in tests
    private \Closure $connect;

    // Current Unix time; replaceable in tests
    private \Closure $clock;

    /**
     * Both arguments are test seams: a connection factory (default Database::openConnection) and a clock (default time).
     */
    public function __construct(?\Closure $connect = null, ?\Closure $clock = null)
    {
        $this->connect = $connect ?? fn(): PDO => Database::openConnection();
        $this->clock = $clock ?? fn(): int => time();
    }

    /**
     * Forget when a failure line was last written (tests).
     */
    public static function resetLogThrottle(): void
    {
        self::$lastLoggedAt = null;
    }

    /**
     * Build the storage key for a bucket and a client address.
     */
    public static function key(string $bucket, string $client): string
    {
        return hash('sha256', $bucket . '|' . $client);
    }

    /**
     * Count one hit. Returns null when the hit is allowed, or the seconds until the window resets when over the limit.
     */
    public function hit(string $bucket, string $client, int $max, int $windowSeconds, int $now): ?int
    {
        $key = self::key($bucket, $client);

        try {
            // Atomic upsert: a new row opens a window; an expired row restarts it; otherwise the count rises.
            // Assignments run left to right and expires_at is assigned last, so each IF sees the old window end.
            // LAST_INSERT_ID(expr) hands this connection the exact count its own statement produced.
            $connection = $this->connection();
            $statement = $connection->prepare(
                'INSERT INTO rate_limits (rate_key, hits, expires_at)
                 VALUES (:key, LAST_INSERT_ID(1), :windowEnd)
                 ON DUPLICATE KEY UPDATE
                    hits = LAST_INSERT_ID(IF(expires_at <= :now1, 1, hits + 1)),
                    expires_at = IF(expires_at <= :now2, :newWindowEnd, expires_at)'
            );
            $statement->execute([
                ':key' => $key,
                ':windowEnd' => $now + $windowSeconds,
                ':now1' => $now,
                ':now2' => $now,
                ':newWindowEnd' => $now + $windowSeconds,
            ]);
            $hits = (int) $connection->lastInsertId();

            // Within the limit: allowed
            if ($hits <= $max) {
                return null;
            }

            // Over the limit: the client waits until the window ends
            $row = $connection->prepare('SELECT expires_at FROM rate_limits WHERE rate_key = :key');
            $row->execute([':key' => $key]);
            $expiresAt = (int) ($row->fetchColumn() ?: $now + $windowSeconds);

            return max(1, $expiresAt - $now);
        } catch (\Throwable $e) {
            $this->logFailure($e);
            return null;
        }
    }

    /**
     * Delete the counters whose window has ended; returns how many rows were removed.
     */
    public function purgeExpired(int $now): int
    {
        try {
            $statement = $this->connection()->prepare('DELETE FROM rate_limits WHERE expires_at <= :now');
            $statement->execute([':now' => $now]);

            return $statement->rowCount();
        } catch (\Throwable $e) {
            $this->logFailure($e);
            return 0;
        }
    }

    /**
     * The service's own connection, opened on first use.
     */
    private function connection(): PDO
    {
        // One connection per service instance, separate from Database::getInstance()
        return $this->connection ??= ($this->connect)();
    }

    /**
     * Write one log line (at most one per minute) for a failure that made the limiter let a request through.
     */
    private function logFailure(\Throwable $error): void
    {
        // Already logged within the last minute: stay quiet
        $now = ($this->clock)();
        if (self::$lastLoggedAt !== null && $now - self::$lastLoggedAt < self::LOG_INTERVAL_SECONDS) {
            return;
        }
        self::$lastLoggedAt = $now;

        // A missing table means the migration has not been run
        if ($error instanceof \PDOException && $error->getCode() === self::TABLE_MISSING) {
            error_log('rate_limits table missing: run scripts/migrations/master-migration.sql (rate limiting is off until then)');
            return;
        }

        error_log('rate limit check failed, request allowed: ' . $error->getMessage());
    }
}
