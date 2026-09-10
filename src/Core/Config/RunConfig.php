<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Config;

final class RunConfig
{
    /**
     * @param  int|null  $id  An externally created run (TIDEN_RUN_ID). When set, the
     *                        reporter adopts it and never calls CreateTestRun.
     * @param  bool  $complete  Undefined means complete, matching commons: only an
     *                          explicit false hands completion to the orchestrator.
     */
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly bool $complete = true,
        public readonly ?string $branch = null,
        public readonly ?string $buildSha = null,
    ) {}
}
