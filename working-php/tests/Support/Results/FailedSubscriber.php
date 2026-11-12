<?php

namespace Tests\Support\Results;

use PHPUnit\Event\Test\Failed as Event;
use PHPUnit\Event\Test\FailedSubscriber as Contract;

/**
 * Test failed an assertion.
 */
class FailedSubscriber implements Contract
{
    public function __construct(private readonly ResultsRecorder $recorder)
    {
    }

    public function notify(Event $event): void
    {
        // Keep the first line of the failure message
        $this->recorder->outcome($event->test()->id(), 'failed', $event->throwable()->message());
    }
}
