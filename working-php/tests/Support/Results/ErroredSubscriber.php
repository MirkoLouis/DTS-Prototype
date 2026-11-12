<?php

namespace Tests\Support\Results;

use PHPUnit\Event\Test\Errored as Event;
use PHPUnit\Event\Test\ErroredSubscriber as Contract;

/**
 * Test raised an error or exception.
 */
class ErroredSubscriber implements Contract
{
    public function __construct(private readonly ResultsRecorder $recorder)
    {
    }

    public function notify(Event $event): void
    {
        // Keep the first line of the error message
        $this->recorder->outcome($event->test()->id(), 'errored', $event->throwable()->message());
    }
}
