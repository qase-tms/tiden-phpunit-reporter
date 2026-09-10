<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Reporter;

use Tiden\PHPUnitReporter\Core\Model\TestResult;

/** Mode "off", or any mode whose required settings are absent. */
final class NullReporter implements InternalReporter
{
    public function startRun(): void {}

    public function addResult(TestResult $result): void {}

    public function complete(): void {}
}
