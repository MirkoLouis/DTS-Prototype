<?php

namespace Tests\Support\Results;

use PHPUnit\Event\Test\Finished as Event;
use PHPUnit\Event\Test\FinishedSubscriber as Contract;

/**
 * End of a test.
 */
class FinishedSubscriber implements Contract
{
    public function __construct(private readonly ResultsRecorder $recorder)
    {
    }

    public function notify(Event $event): void
    {
        // Close the test row with its assertion count and end time
        $t = $event->telemetryInfo()->time();
        $this->recorder->finished($event->test()->id(), $event->numberOfAssertionsPerformed(), $t->seconds() + $t->nanoseconds() / 1e9);
    }
}
