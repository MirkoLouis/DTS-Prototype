<?php

namespace Tests\Support;

/**
 * A queue job for tests: it sleeps, then finishes or fails, so a test can observe a job while it runs.
 */
final class SleepJob
{
    public function __construct(private int $seconds, private bool $fail = false)
    {
    }

    // Stay busy for the configured time, then end the way the test asked
    public function handle(): void
    {
        sleep($this->seconds);
        if ($this->fail) {
            throw new \RuntimeException('SleepJob failed on purpose.');
        }
    }
}
