<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Event;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\WarningTriggered;
use PHPUnit\Event\Test\WarningTriggeredSubscriber;
use Tiden\PHPUnitReporter\Core\Model\Status;
use Tiden\PHPUnitReporter\Reporter;

final class TestWarningTriggeredSubscriber implements WarningTriggeredSubscriber
{
    public function __construct(private readonly Reporter $reporter) {}

    public function notify(WarningTriggered $event): void
    {
        $test = $event->test();

        if ($test instanceof TestMethod) {
            $this->reporter->updateStatus($test, Status::Invalid, $event->message());
        }
    }
}
