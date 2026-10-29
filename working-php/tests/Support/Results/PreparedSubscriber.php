<?php

namespace Tests\Support\Results;

use PHPUnit\Event\Test\Prepared as Event;
use PHPUnit\Event\Test\PreparedSubscriber as Contract;

/**
 * Start of a test.
 */
class PreparedSubscriber implements Contract
{
    public function __construct(private readonly ResultsRecorder $recorder)
    {
    }

    public function notify(Event $event): void
    {
        // Remember identity, data set name and start time
        $test = $event->test();
        $dataSet = '';
        if ($test->isTestMethod()) {
            $data = $test->testData();
            if ($data->hasDataFromDataProvider()) {
                $dataSet = (string) $data->dataFromDataProvider()->dataSetName();
            }
        }
        $t = $event->telemetryInfo()->time();
        $this->recorder->prepared(
            $test->id(),
            $test->isTestMethod() ? $test->className() : basename($test->file()),
            $test->isTestMethod() ? $test->methodName() : $test->name(),
            $dataSet,
            $test->file(),
            $t->seconds() + $t->nanoseconds() / 1e9
        );
    }
}
