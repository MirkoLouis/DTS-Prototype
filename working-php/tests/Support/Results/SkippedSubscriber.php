<?php

namespace Tests\Support\Results;

use PHPUnit\Event\Test\Skipped as Event;
use PHPUnit\Event\Test\SkippedSubscriber as Contract;

/**
 * Test was skipped.
 */
class SkippedSubscriber implements Contract
{
    public function __construct(private readonly ResultsRecorder $recorder)
    {
    }

    public function notify(Event $event): void
    {
        // Keep the first line of the skip reason
        $this->recorder->outcome($event->test()->id(), 'skipped', $event->message());
    }
}
