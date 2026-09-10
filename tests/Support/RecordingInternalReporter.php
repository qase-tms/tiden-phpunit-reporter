<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Support;

use Tiden\PHPUnitReporter\Core\Model\TestResult;
use Tiden\PHPUnitReporter\Core\Reporter\InternalReporter;

/** Keeps the results the PHPUnit layer produced, so tests can inspect them. */
final class RecordingInternalReporter implements InternalReporter
{
    /** @var list<TestResult> */
    public array $results = [];

    public int $runsStarted = 0;

    public int $completions = 0;

    public function startRun(): void
    {
        $this->runsStarted++;
    }

    public function addResult(TestResult $result): void
    {
        $this->results[] = $result;
    }

    public function complete(): void
    {
        $this->completions++;
    }

    public function bySignatureSuffix(string $suffix): ?TestResult
    {
        foreach ($this->results as $result) {
            if (str_ends_with($result->signature, $suffix)) {
                return $result;
            }
        }

        return null;
    }
}
