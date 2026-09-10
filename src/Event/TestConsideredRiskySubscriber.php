<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Event;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\ConsideredRisky;
use PHPUnit\Event\Test\ConsideredRiskySubscriber;
use Tiden\PHPUnitReporter\Core\Model\Status;
use Tiden\PHPUnitReporter\Reporter;

final class TestConsideredRiskySubscriber implements ConsideredRiskySubscriber
{
    public function __construct(private readonly Reporter $reporter) {}

    public function notify(ConsideredRisky $event): void
    {
        $test = $event->test();

        if ($test instanceof TestMethod) {
            $this->reporter->updateStatus($test, Status::Invalid, $event->message());
        }
    }
}
