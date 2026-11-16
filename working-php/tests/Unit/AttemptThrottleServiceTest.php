<?php

namespace Tests\Unit;

use App\Modules\Auth\AttemptThrottleService;
use Tests\TestCase;

class AttemptThrottleServiceTest extends TestCase
{
    // Fixed reference time so every test controls the clock
    private const T = 1_800_000_000;

    // Build a login key for an email and IP
    private function loginKey(string $email, string $ip = '192.168.1.10'): string
    {
        return AttemptThrottleService::key('login', strtolower(trim($email)) . '|' . $ip);
    }

    // Record MAX_FAILURES failures at the same moment, which locks the key
    private function lock(string $key): void
    {
        for ($i = 0; $i < AttemptThrottleService::MAX_FAILURES; $i++) {
            AttemptThrottleService::recordFailure($key, self::T);
        }
    }

    // The limit is five failures inside one minute
    public function test_the_limit_is_five_failures(): void
    {
        $this->assertSame(5, AttemptThrottleService::MAX_FAILURES);
    }

    // MAX_FAILURES - 1 failures inside one minute do not lock; the MAX_FAILURES-th locks the key for a full minute
    public function test_locks_on_the_last_allowed_failure_within_a_minute(): void
    {
        $key = $this->loginKey('cash.unit');
        $now = self::T;

        // The failures before the limit do not lock
        for ($i = 1; $i < AttemptThrottleService::MAX_FAILURES; $i++) {
            $now = self::T + 10 * $i;
            $this->assertFalse(AttemptThrottleService::recordFailure($key, $now));
            $this->assertSame(0, AttemptThrottleService::secondsLocked($key, $now));
        }

        // The failure that reaches the limit locks
        $now += 10;
        $this->assertTrue(AttemptThrottleService::recordFailure($key, $now));
        $this->assertSame(60, AttemptThrottleService::secondsLocked($key, $now));
    }

    // Failures spread over more than a minute each restart the window
    public function test_failures_more_than_a_minute_apart_do_not_lock(): void
    {
        $key = $this->loginKey('cash.unit');

        // Each failure falls outside the previous window
        foreach ([self::T, self::T + 61, self::T + 122] as $now) {
            $this->assertFalse(AttemptThrottleService::recordFailure($key, $now));
            $this->assertSame(0, AttemptThrottleService::secondsLocked($key, $now));
        }
    }

    // The lock lifts once the minute has passed
    public function test_lock_expires_after_one_minute(): void
    {
        $key = $this->loginKey('cash.unit');
        $this->lock($key);

        $this->assertSame(1, AttemptThrottleService::secondsLocked($key, self::T + 59));
        $this->assertSame(0, AttemptThrottleService::secondsLocked($key, self::T + 61));
    }

    // A failure right after the lock expires starts a fresh count instead of relocking
    public function test_failure_after_expired_lock_starts_new_window(): void
    {
        $key = $this->loginKey('cash.unit');
        $this->lock($key);

        $this->assertFalse(AttemptThrottleService::recordFailure($key, self::T + 60));
        $this->assertSame(0, AttemptThrottleService::secondsLocked($key, self::T + 60));
    }

    // Locking one email leaves another email on the same IP untouched
    public function test_lock_is_per_email_not_per_ip(): void
    {
        $locked = $this->loginKey('cash.unit');
        $this->lock($locked);

        $this->assertSame(60, AttemptThrottleService::secondsLocked($locked, self::T));
        $this->assertSame(0, AttemptThrottleService::secondsLocked($this->loginKey('personnel.unit'), self::T));
        $this->assertSame($locked, $this->loginKey('  CASH.Unit '));
    }

    // PIN locks are scoped to one user ID
    public function test_pin_lock_is_per_user(): void
    {
        $key = AttemptThrottleService::key('pin', '5');
        $this->lock($key);

        $this->assertSame(60, AttemptThrottleService::secondsLocked($key, self::T));
        $this->assertSame(0, AttemptThrottleService::secondsLocked(AttemptThrottleService::key('pin', '6'), self::T));
    }

    // Clearing forgets earlier failures
    public function test_clear_resets_failures(): void
    {
        $key = $this->loginKey('cash.unit');
        for ($i = 1; $i < AttemptThrottleService::MAX_FAILURES; $i++) {
            AttemptThrottleService::recordFailure($key, self::T);
        }
        AttemptThrottleService::clear($key);

        $this->assertFalse(AttemptThrottleService::recordFailure($key, self::T + 1));
        $this->assertSame(0, AttemptThrottleService::secondsLocked($key, self::T + 1));
    }
}
