<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Event;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;
use Tiden\PHPUnitReporter\Reporter;
use Tiden\PHPUnitReporter\StatusDetector;

final class TestMarkedIncompleteSubscriber implements MarkedIncompleteSubscriber
{
    public function __construct(private readonly Reporter $reporter) {}

    public function notify(MarkedIncomplete $event): void
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
