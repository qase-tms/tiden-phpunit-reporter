<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Event;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use Tiden\PHPUnitReporter\Reporter;
use Tiden\PHPUnitReporter\StatusDetector;

final class TestFailedSubscriber implements FailedSubscriber
{
    public function __construct(private readonly Reporter $reporter) {}

    public function notify(Failed $event): void
    {
        $test = $event->test();

        if (! $test instanceof TestMethod) {
            return;
        }

        $throwable = $event->throwable();

        $this->reporter->updateStatus(
            $test,
            StatusDetector::forFailure($throwable),
            $throwable->message(),
            $throwable->asString(),
        );
    }
}
