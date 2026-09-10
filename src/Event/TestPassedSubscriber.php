<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Event;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PassedSubscriber;
use Tiden\PHPUnitReporter\Core\Model\Status;
use Tiden\PHPUnitReporter\Reporter;

final class TestPassedSubscriber implements PassedSubscriber
{
    public function __construct(private readonly Reporter $reporter) {}

    public function notify(Passed $event): void
    {
        $test = $event->test();

        if ($test instanceof TestMethod) {
            $this->reporter->updateStatus($test, Status::Passed);
        }
    }
}
