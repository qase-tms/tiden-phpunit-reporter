<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Event;

use PHPUnit\Event\TestRunner\Started;
use PHPUnit\Event\TestRunner\StartedSubscriber;
use Tiden\PHPUnitReporter\Reporter;

final class TestRunnerStartedSubscriber implements StartedSubscriber
{
    public function __construct(private readonly Reporter $reporter) {}

    public function notify(Started $event): void
    {
        $this->reporter->startTestRun();
    }
}
