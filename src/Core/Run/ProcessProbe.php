<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Run;

/**
 * Answers whether a process is still running.
 *
 * A seam rather than an inline `function_exists('posix_kill')` for a reason
 * that showed up in the gate: reaching for a global made the two branches that
 * matter — "the worker is provably gone" and "we cannot tell" — impossible to
 * exercise in the same process, so each test of one had to be skipped whenever
 * the other was possible. Skipped is not passed.
 */
interface ProcessProbe
{
    /**
     * @return bool|null true running, false provably gone, null unknown.
     *                   Unknown is a real answer, not an error: without
     *                   ext-posix there is nothing to ask.
     */
    public function isRunning(int $pid): ?bool;
}
