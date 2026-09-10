<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Run;

/** The run a worker joined, and whether it arrived too late to report into it. */
final class RunHandle
{
    public function __construct(
        public readonly int $runSeq,
        public readonly bool $alreadyCompleted = false,
    ) {}
}
