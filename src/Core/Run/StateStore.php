<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Run;

use Tiden\PHPUnitReporter\Core\Exception\TidenException;

/**
 * Cross-process coordination for ParaTest, through one flock-ed JSON file.
 *
 * ParaTest spawns N worker processes, each of which bootstraps this extension
 * separately, and its parent process does not run PHPUnit's event system — so
 * there is no parent to elect. The run must therefore be created exactly once
 * and completed exactly once by whichever worker turns out to be last.
 *
 * This is modelled on qase/php-commons' StateManager and fixes three things
 * about it:
 *
 *  1. It does not write inside vendor/. The state file lives in the temp
 *     directory, keyed by the ParaTest parent pid so concurrent runs of the
 *     same project do not share one.
 *
 *  2. It records WHICH workers registered, not just how many. A bare refcount
 *     cannot tell "still running" from "died".
 *
 *  3. It gates completion on liveness. A worker that dies without finishing
 *     leaves the run open on purpose. Without ext-posix the liveness check is
 *     unavailable and the run is also left open — same safe direction, minus
 *     the diagnostic naming the dead pid.
 */
final class StateStore
{
    /**
     * errno for "operation not permitted". ext-posix exposes no errno constants,
     * and EPERM is 1 on every POSIX platform.
     */
    private const EPERM = 1;

    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path) {}

    public static function defaultPath(?string $configured = null, ?string $cwd = null): string
    {
        if ($configured !== null && $configured !== '') {
            return $configured;
        }

        // The ParaTest parent pid is the same for every worker of one
        // invocation and different across invocations, which is exactly the
        // scope a run needs. Without ext-posix we are single-process anyway.
        $key = function_exists('posix_getppid') ? posix_getppid() : getmypid();
        $project = substr(hash('sha256', $cwd ?? (getcwd() ?: '.')), 0, 12);

        return sprintf('%s/tiden-phpunit-%s-%s.json', sys_get_temp_dir(), $project, (string) $key);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Register this worker and make sure a run exists, creating it under the
     * lock so that N workers produce exactly one CreateTestRun call.
     *
     * A recorded run is ALWAYS adopted, including one already completed. The
     * earlier design deleted the state file on completion, and a worker that
     * started after the first one finished then found no file and created a
     * second run — one ParaTest invocation appearing as two runs, each holding
     * part of the results. Adopting a completed run instead makes the late
     * worker fail loudly ("results are locked") rather than silently splitting
     * the run in two.
     *
     * @param  callable(): int  $createRun  Invoked at most once per invocation.
     */
    public function startRun(int $pid, callable $createRun): RunHandle
    {
        return $this->withLock(function (array $state) use ($pid, $createRun): array {
            if (self::isStale($state)) {
                $state = self::emptyState();
            }

            $state['owner'] = self::owner();

            if (! is_int($state['runId'] ?? null)) {
                $state['runId'] = $createRun();
            }

            $state['workers'][(string) $pid] = ['startedAt' => time(), 'done' => false];

            return [$state, new RunHandle((int) $state['runId'], ($state['completed'] ?? false) === true)];
        });
    }

    /**
     * Mark this worker finished and decide whether it owns completion.
     *
     * The file_path counters are accumulated here rather than read from the
     * finishing worker's own tally: under ParaTest each worker only sees the
     * tests it ran, so "no result anywhere carried a file_path" is only
     * answerable across all of them.
     */
    public function finish(int $pid, int $resolvedPaths = 0, int $omittedPaths = 0): FinishDecision
    {
        return $this->withLock(function (array $state) use ($pid, $resolvedPaths, $omittedPaths): array {
            $existing = $state['workers'][(string) $pid] ?? null;

            $state['workers'][(string) $pid] = [
                'startedAt' => is_array($existing) && is_int($existing['startedAt'] ?? null) ? $existing['startedAt'] : time(),
                'done' => true,
            ];

            $state['resolvedPaths'] = (int) ($state['resolvedPaths'] ?? 0) + $resolvedPaths;
            $state['omittedPaths'] = (int) ($state['omittedPaths'] ?? 0) + $omittedPaths;

            $alive = [];
            $dead = [];

            // Shape-checked rather than assumed: this comes off disk, and a
            // truncated or hand-edited file may be missing keys entirely.
            /** @var array<string, mixed> $workers */
            $workers = $state['workers'];

            foreach ($workers as $otherPid => $worker) {
                if (is_array($worker) && ($worker['done'] ?? false) === true) {
                    continue;
                }

                if (self::isAlive((int) $otherPid)) {
                    $alive[] = (int) $otherPid;
                } else {
                    $dead[] = (int) $otherPid;
                }
            }

            $resolved = (int) $state['resolvedPaths'];
            $omitted = (int) $state['omittedPaths'];

            // Someone still running may yet be the last one; let them decide.
            if ($alive !== []) {
                return [$state, FinishDecision::wait($resolved, $omitted)];
            }

            if ($dead !== []) {
                return [$state, FinishDecision::abandoned($dead, $resolved, $omitted)];
            }

            return [$state, FinishDecision::complete($resolved, $omitted)];
        });
    }

    /**
     * Record that the run has been completed, keeping the file so that a worker
     * arriving afterwards adopts the run instead of opening a second one.
     *
     * The file is left in the temp directory. It is small, it is keyed by the
     * ParaTest parent, and the owner check in startRun() makes a leftover one
     * harmless to the next invocation.
     */
    public function markCompleted(): void
    {
        $this->withLock(static function (array $state): array {
            $state['completed'] = true;

            return [$state, null];
        });
    }

    /**
     * Without ext-posix we cannot tell a dead pid from a live one. Reporting
     * "alive" is the conservative answer: it makes the run wait rather than
     * complete, so an unfinished run is never completed on a guess.
     */
    private static function isAlive(int $pid): bool
    {
        if (! function_exists('posix_kill')) {
            return true;
        }

        if (posix_kill($pid, 0)) {
            return true;
        }

        // EPERM means the process exists but belongs to another user. Only
        // ESRCH ("no such process") actually proves it is gone.
        return posix_get_last_error() === self::EPERM;
    }

    /**
     * @template T
     *
     * @param  callable(array<string, mixed>): array{0: array<string, mixed>, 1: T}  $mutate
     * @return T
     */
    private function withLock(callable $mutate): mixed
    {
        $handle = $this->open();

        if (! flock($handle, LOCK_EX)) {
            throw new TidenException(sprintf('Could not lock the reporter state file "%s".', $this->path));
        }

        try {
            [$state, $return] = $mutate($this->read($handle));
            $this->write($handle, $state);

            return $return;
        } finally {
            flock($handle, LOCK_UN);
        }
    }

    /** @return resource */
    private function open()
    {
        if ($this->handle === null) {
            $handle = @fopen($this->path, 'c+');

            if ($handle === false) {
                throw new TidenException(sprintf('Could not open the reporter state file "%s".', $this->path));
            }

            $this->handle = $handle;
        }

        return $this->handle;
    }

    /**
     * @param  resource  $handle
     * @return array<string, mixed>
     */
    private function read($handle): array
    {
        rewind($handle);
        $contents = stream_get_contents($handle);

        /** @var mixed $decoded */
        $decoded = is_string($contents) && trim($contents) !== '' ? json_decode($contents, true) : null;

        if (! is_array($decoded) || ! is_array($decoded['workers'] ?? null)) {
            // A truncated or hand-edited file is not trusted in part: the only
            // thing worth salvaging is a run id, and only if it is really one.
            $state = self::emptyState();

            if (is_array($decoded) && is_int($decoded['runId'] ?? null)) {
                $state['runId'] = $decoded['runId'];
                $state['owner'] = $decoded['owner'] ?? null;
            }

            return $state;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function emptyState(): array
    {
        return ['runId' => null, 'owner' => self::owner(), 'completed' => false, 'workers' => []];
    }

    /**
     * Is this file a leftover from an earlier invocation?
     *
     * Only a POSITIVE answer resets it. The two failure modes are not
     * symmetric: wrongly adopting an old run makes every result be rejected,
     * which is loud and recoverable, while wrongly starting a fresh one splits
     * a single ParaTest invocation across two runs that each look complete. So
     * anything short of proof that the previous invocation is gone adopts.
     *
     * Proof is that the recorded owner — the parent every worker of that
     * invocation shared — is no longer running. Without ext-posix there is no
     * proof available, and the file is adopted.
     *
     * @param  array<string, mixed>  $state
     */
    private static function isStale(array $state): bool
    {
        $owner = $state['owner'] ?? null;

        if (! is_int($owner) || $owner === self::owner()) {
            return false;
        }

        return ! self::isAlive($owner);
    }

    /**
     * Identifies one ParaTest invocation: every worker shares a parent process,
     * and a later invocation has a different one.
     */
    private static function owner(): int
    {
        return function_exists('posix_getppid') ? posix_getppid() : (getmypid() ?: 0);
    }

    /**
     * @param  resource  $handle
     * @param  array<string, mixed>  $state
     */
    private function write($handle, array $state): void
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR);

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $json);
        fflush($handle);
    }
}
