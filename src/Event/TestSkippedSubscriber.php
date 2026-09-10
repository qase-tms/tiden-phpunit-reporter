<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Event;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;
use Tiden\PHPUnitReporter\Core\Model\Status;
use Tiden\PHPUnitReporter\Reporter;

final class TestSkippedSubscriber implements SkippedSubscriber
{
    public function __construct(private readonly Reporter $reporter) {}

    public function notify(Skipped $event): void
    {
        $test = $event->test();

        if ($test instanceof TestMethod) {
            $this->reporter->updateStatus($test, Status::Skipped, $event->message());
        }
    }
}
