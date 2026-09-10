<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Reporter;

use Tiden\PHPUnitReporter\Core\Model\TestResult;

/** The seam the mode dispatch produces; the PHPUnit layer only ever sees this. */
interface InternalReporter
{
    public function startRun(): void;

    public function addResult(TestResult $result): void;

    /** Flush anything buffered and, if this process owns it, close the run. */
    public function complete(): void;
}
