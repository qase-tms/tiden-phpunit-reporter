<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Event;

use PHPUnit\Event\TestRunner\Finished;
use PHPUnit\Event\TestRunner\FinishedSubscriber;
use Tiden\PHPUnitReporter\Reporter;

final class TestRunnerFinishedSubscriber implements FinishedSubscriber
{
    public function __construct(private readonly Reporter $reporter) {}

    public function notify(Finished $event): void
    {
        $this->reporter->completeTestRun();
    }
}
