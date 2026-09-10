<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Run;

/**
 * What a worker should do once it has finished its own share of the run,
 * together with the run-wide file_path tally the decision was made against.
 */
final class FinishDecision
{
    /** @param list<int> $deadWorkers */
    private function __construct(
        public readonly string $action,
        public readonly int $resolvedPaths = 0,
        public readonly int $omittedPaths = 0,
        public readonly array $deadWorkers = [],
    ) {}

    /** Every worker is accounted for: this one completes the run. */
    public static function complete(int $resolvedPaths = 0, int $omittedPaths = 0): self
    {
        return new self('complete', $resolvedPaths, $omittedPaths);
    }

    /** Other workers are still running; one of them will complete it. */
    public static function wait(int $resolvedPaths = 0, int $omittedPaths = 0): self
    {
        return new self('wait', $resolvedPaths, $omittedPaths);
    }

    /**
     * A worker died without reporting. The run is deliberately LEFT OPEN: an
     * incomplete run cannot pass a quality gate, whereas a completed run whose
     * results are silently missing reads as a pass.
     *
     * @param  list<int>  $deadWorkers
     */
    public static function abandoned(array $deadWorkers, int $resolvedPaths = 0, int $omittedPaths = 0): self
    {
        return new self('abandoned', $resolvedPaths, $omittedPaths, $deadWorkers);
    }

    public function shouldComplete(): bool
    {
        return $this->action === 'complete';
    }

    public function isAbandoned(): bool
    {
        return $this->action === 'abandoned';
    }

    /**
     * True when paths were seen and none of them resolved — every case in this
     * run is unlinkable, which is a misconfiguration rather than an edge case.
     */
    public function everyPathUnresolved(): bool
    {
        return $this->resolvedPaths === 0 && $this->omittedPaths > 0;
    }
}
