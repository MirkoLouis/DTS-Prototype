<?php

namespace Tests\Support\Results;

use PHPUnit\Event\Test\Passed as Event;
use PHPUnit\Event\Test\PassedSubscriber as Contract;

/**
 * Test passed.
 */
class PassedSubscriber implements Contract
{
    public function __construct(private readonly ResultsRecorder $recorder)
    {
    }

    public function notify(Event $event): void
    {
        // Passing is the default; recorded so the test exists even without other events
        $this->recorder->outcome($event->test()->id(), 'passed');
    }
}
