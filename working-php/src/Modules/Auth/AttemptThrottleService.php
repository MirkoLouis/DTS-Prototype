<?php

namespace App\Modules\Auth;

use App\Common\Database\Database;

/**
 * Failed-attempt limiting for login and Security PIN entry.
 *
 * MAX_FAILURES failures within WINDOW_SECONDS lock the key for LOCKOUT_SECONDS. Keys are
 * SHA-256 digests of "scope|subject", so neither emails nor IPs are stored in clear.
 * The table is written outside any workflow transaction, so a rollback never erases a failure.
 */
class AttemptThrottleService
{
    public const MAX_FAILURES = 3;
    public const WINDOW_SECONDS = 60;
    public const LOCKOUT_SECONDS = 60;
    public const MESSAGE = 'Too many failed attempts. Timed out for 1 minute.';

    /**
     * Build the throttle key for a scope ('login' or 'pin') and its subject.
     */
    public static function key(string $scope, string $subject): string
    {
        return hash('sha256', $scope . '|' . $subject);
    }

    /**
     * Seconds left on the key's lock; 0 when it is not locked.
     */
    public static function secondsLocked(string $key, int $now): int
    {
        // Fail open when the migration has not been run yet
        try {
            $row = Database::getInstance()->query('SELECT locked_until FROM attempt_throttles WHERE throttle_key = :k', [':k' => $key])->fetch();
        } catch (\PDOException $e) {
            self::failOpenOrRethrow($e);
            return 0;
        }

        // No row or no lock means the key is free
        if (!$row || $row['locked_until'] === null) {
            return 0;
        }

        return max(0, (int) $row['locked_until'] - $now);
    }

    /**
     * Record one failure. Returns true when this failure triggered the lock.
     */
    public static function recordFailure(string $key, int $now): bool
    {
        // A failure while already locked neither extends nor resets the lock
        if (self::secondsLocked($key, $now) > 0) {
            return false;
        }

        // Atomic upsert: restart the window when it has passed or a previous lock has expired,
        // otherwise count the failure; assignments run left to right on the updated values
        try {
            Database::getInstance()->query(
                'INSERT INTO attempt_throttles (throttle_key, failures, first_failed_at, locked_until)
                 VALUES (:k, 1, :now1, NULL)
                 ON DUPLICATE KEY UPDATE
                    failures = IF(first_failed_at < :windowStart OR (locked_until IS NOT NULL AND locked_until <= :now2), 1, failures + 1),
                    first_failed_at = IF(failures = 1, :now3, first_failed_at),
                    locked_until = IF(failures >= :max1, :lockUntil1, NULL)',
                [
                    ':k' => $key,
                    ':now1' => $now,
                    ':windowStart' => $now - self::WINDOW_SECONDS,
                    ':now2' => $now,
                    ':now3' => $now,
                    ':max1' => self::MAX_FAILURES,
                    ':lockUntil1' => $now + self::LOCKOUT_SECONDS,
                ]
            );

            // Read back the row this call just wrote
            $row = Database::getInstance()->query('SELECT failures, locked_until FROM attempt_throttles WHERE throttle_key = :k', [':k' => $key])->fetch();
        } catch (\PDOException $e) {
            self::failOpenOrRethrow($e);
            return false;
        }

        // This failure triggered the lock when it set locked_until to now + LOCKOUT_SECONDS
        return $row
            && (int) $row['failures'] >= self::MAX_FAILURES
            && $row['locked_until'] !== null
            && (int) $row['locked_until'] === $now + self::LOCKOUT_SECONDS;
    }

    /**
     * Forget all failures for the key (after a successful attempt).
     */
    public static function clear(string $key): void
    {
        // Fail open when the migration has not been run yet
        try {
            Database::getInstance()->query('DELETE FROM attempt_throttles WHERE throttle_key = :k', [':k' => $key]);
        } catch (\PDOException $e) {
            self::failOpenOrRethrow($e);
        }
    }

    /**
     * Swallow only "table missing" (SQLSTATE 42S02) with a log line; rethrow anything else.
     *
     * @throws \PDOException for every other database error
     */
    private static function failOpenOrRethrow(\PDOException $e): void
    {
        // Any other database error must still surface
        if ($e->getCode() !== '42S02') {
            throw $e;
        }

        error_log('attempt_throttles table missing: run scripts/migrations/master-migration.sql');
    }
}
