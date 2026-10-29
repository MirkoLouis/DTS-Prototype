<?php

namespace Tests\Support\Results;

use PHPUnit\Event\Test\ConsideredRisky as Event;
use PHPUnit\Event\Test\ConsideredRiskySubscriber as Contract;

/**
 * Test was considered risky.
 */
class RiskySubscriber implements Contract
{
    public function __construct(private readonly ResultsRecorder $recorder)
    {
    }

    public function notify(Event $event): void
    {
        // Keep the first line of the risk message
        $this->recorder->outcome($event->test()->id(), 'risky', $event->message());
    }
}
