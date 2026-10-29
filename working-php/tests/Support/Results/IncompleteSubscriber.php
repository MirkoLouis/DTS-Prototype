<?php

namespace Tests\Support\Results;

use PHPUnit\Event\Test\MarkedIncomplete as Event;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber as Contract;

/**
 * Test was marked incomplete.
 */
class IncompleteSubscriber implements Contract
{
    public function __construct(private readonly ResultsRecorder $recorder)
    {
    }

    public function notify(Event $event): void
    {
        // Keep the first line of the reason
        $this->recorder->outcome($event->test()->id(), 'incomplete', $event->throwable()->message());
    }
}
