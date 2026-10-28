<?php

namespace Tests\Unit;

use App\Core\AttemptThrottle;
use Tests\TestCase;

class AttemptThrottleTest extends TestCase
{
    // Fixed reference time so every test controls the clock
    private const T = 1_800_000_000;

    // Build a login key for an email and IP
    private function loginKey(string $email, string $ip = '192.168.1.10'): string
    {
        return AttemptThrottle::key('login', strtolower(trim($email)) . '|' . $ip);
    }

    // Three failures inside one minute lock the key for a full minute
    public function test_locks_on_third_failure_within_a_minute(): void
    {
        $key = $this->loginKey('cash.unit');

        $this->assertFalse(AttemptThrottle::recordFailure($key, self::T));
        $this->assertFalse(AttemptThrottle::recordFailure($key, self::T + 10));
        $this->assertSame(0, AttemptThrottle::secondsLocked($key, self::T + 10));
        $this->assertTrue(AttemptThrottle::recordFailure($key, self::T + 20));
        $this->assertSame(60, AttemptThrottle::secondsLocked($key, self::T + 20));
    }

    // Failures spread over more than a minute each restart the window
    public function test_failures_more_than_a_minute_apart_do_not_lock(): void
    {
        $key = $this->loginKey('cash.unit');

        // Each failure falls outside the previous window
        foreach ([self::T, self::T + 61, self::T + 122] as $now) {
            $this->assertFalse(AttemptThrottle::recordFailure($key, $now));
            $this->assertSame(0, AttemptThrottle::secondsLocked($key, $now));
        }
    }

    // The lock lifts once the minute has passed
    public function test_lock_expires_after_one_minute(): void
    {
        $key = $this->loginKey('cash.unit');
        AttemptThrottle::recordFailure($key, self::T);
        AttemptThrottle::recordFailure($key, self::T);
        AttemptThrottle::recordFailure($key, self::T);

        $this->assertSame(1, AttemptThrottle::secondsLocked($key, self::T + 59));
        $this->assertSame(0, AttemptThrottle::secondsLocked($key, self::T + 61));
    }

    // A failure right after the lock expires starts a fresh count instead of relocking
    public function test_failure_after_expired_lock_starts_new_window(): void
    {
        $key = $this->loginKey('cash.unit');
        AttemptThrottle::recordFailure($key, self::T);
        AttemptThrottle::recordFailure($key, self::T);
        AttemptThrottle::recordFailure($key, self::T);

        $this->assertFalse(AttemptThrottle::recordFailure($key, self::T + 60));
        $this->assertSame(0, AttemptThrottle::secondsLocked($key, self::T + 60));
    }

    // Locking one email leaves another email on the same IP untouched
    public function test_lock_is_per_email_not_per_ip(): void
    {
        $locked = $this->loginKey('cash.unit');
        for ($i = 0; $i < 3; $i++) {
            AttemptThrottle::recordFailure($locked, self::T);
        }

        $this->assertSame(60, AttemptThrottle::secondsLocked($locked, self::T));
        $this->assertSame(0, AttemptThrottle::secondsLocked($this->loginKey('personnel.unit'), self::T));
        $this->assertSame($locked, $this->loginKey('  CASH.Unit '));
    }

    // PIN locks are scoped to one user ID
    public function test_pin_lock_is_per_user(): void
    {
        $key = AttemptThrottle::key('pin', '5');
        for ($i = 0; $i < 3; $i++) {
            AttemptThrottle::recordFailure($key, self::T);
        }

        $this->assertSame(60, AttemptThrottle::secondsLocked($key, self::T));
        $this->assertSame(0, AttemptThrottle::secondsLocked(AttemptThrottle::key('pin', '6'), self::T));
    }

    // Clearing forgets earlier failures
    public function test_clear_resets_failures(): void
    {
        $key = $this->loginKey('cash.unit');
        AttemptThrottle::recordFailure($key, self::T);
        AttemptThrottle::recordFailure($key, self::T);
        AttemptThrottle::clear($key);

        $this->assertFalse(AttemptThrottle::recordFailure($key, self::T + 1));
        $this->assertSame(0, AttemptThrottle::secondsLocked($key, self::T + 1));
    }
}
