<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Config;

final class LoggingConfig
{
    public function __construct(
        public readonly bool $console = true,
        public readonly bool $file = false,
    ) {}
}
