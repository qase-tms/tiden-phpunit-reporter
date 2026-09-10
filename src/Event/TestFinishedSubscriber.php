<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Event;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use Tiden\PHPUnitReporter\Reporter;

final class TestFinishedSubscriber implements FinishedSubscriber
{
    public function __construct(private readonly Reporter $reporter) {}

    public function notify(Finished $event): void
    {
        $test = $event->test();

        if ($test instanceof TestMethod) {
            $this->reporter->completeTest($test);
        }
    }
}
