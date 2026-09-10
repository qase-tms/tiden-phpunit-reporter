<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Event;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use Tiden\PHPUnitReporter\Reporter;

final class TestPreparedSubscriber implements PreparedSubscriber
{
    public function __construct(private readonly Reporter $reporter) {}

    public function notify(Prepared $event): void
    {
        $test = $event->test();

        if ($test instanceof TestMethod) {
            $this->reporter->startTest($test);
        }
    }
}
