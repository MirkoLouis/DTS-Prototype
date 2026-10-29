<?php

namespace Tests\Support\Results;

use App\Research\ResultWriter;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * PHPUnit extension: records every test outcome into the run folder for the thesis charts.
 */
class ResultsExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        // Make sure the run folder exists (bootstrap.php normally created it already)
        ResultWriter::dir();

        // One recorder per PHPUnit process; every subscriber feeds it
        $recorder = new ResultsRecorder();
        $facade->registerSubscribers(
            new PreparedSubscriber($recorder),
            new PassedSubscriber($recorder),
            new FailedSubscriber($recorder),
            new ErroredSubscriber($recorder),
            new SkippedSubscriber($recorder),
            new IncompleteSubscriber($recorder),
            new RiskySubscriber($recorder),
            new FinishedSubscriber($recorder),
            new RunFinishedSubscriber($recorder)
        );
    }
}
