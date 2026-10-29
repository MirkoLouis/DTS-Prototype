<?php

namespace Tests\Support\Results;

use PHPUnit\Event\TestRunner\Finished as Event;
use PHPUnit\Event\TestRunner\FinishedSubscriber as Contract;

/**
 * End of the whole run.
 */
class RunFinishedSubscriber implements Contract
{
    public function __construct(private readonly ResultsRecorder $recorder)
    {
    }

    public function notify(Event $event): void
    {
        // Write the result files (parent process only)
        $this->recorder->write();
    }
}
