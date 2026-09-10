<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Event;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationErrored;
use PHPUnit\Event\Test\PreparationErroredSubscriber as PHPUnitPreparationErroredSubscriber;
use Tiden\PHPUnitReporter\Reporter;
use Tiden\PHPUnitReporter\StatusDetector;

/**
 * The test never ran because its setup blew up. There is no Prepared and no
 * Finished event for it, so this is the only chance to record that it happened
 * at all — dropping it would shrink the run silently.
 */
final class TestPreparationErroredSubscriber implements PHPUnitPreparationErroredSubscriber
{
    public function __construct(private readonly Reporter $reporter) {}

    public function notify(PreparationErrored $event): void
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
        $this->reporter->completeTest($test);
    }
}
