<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Config;

final class ReportConfig
{
    public function __construct(
        public readonly ?string $path = null,
        public readonly string $format = 'json',
    ) {}
}
