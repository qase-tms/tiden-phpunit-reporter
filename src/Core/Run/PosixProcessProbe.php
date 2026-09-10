<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Run;

/** Asks the OS, when ext-posix is available to ask with. */
final class PosixProcessProbe implements ProcessProbe
{
    /**
     * errno for "operation not permitted". ext-posix exposes no errno
     * constants, and EPERM is 1 on every POSIX platform.
     */
    private const EPERM = 1;

    public function isRunning(int $pid): ?bool
    {
        if (! function_exists('posix_kill')) {
            return null;
        }

        if (posix_kill($pid, 0)) {
            return true;
        }

        // EPERM means the process exists but belongs to another user. Only
        // ESRCH ("no such process") actually proves it is gone.
        return posix_get_last_error() === self::EPERM;
    }
}
