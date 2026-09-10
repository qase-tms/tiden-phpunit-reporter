<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Config;

final class BatchConfig
{
    /** Matches commons: DEFAULT_BATCH_SIZE 200, and the API's documented 1..2000 per call. */
    public const DEFAULT_SIZE = 200;

    public const MAX_SIZE = 2000;

    public readonly int $size;

    public function __construct(?int $size = null)
    {
        $size ??= self::DEFAULT_SIZE;

        $this->size = max(1, min($size, self::MAX_SIZE));
    }
}
