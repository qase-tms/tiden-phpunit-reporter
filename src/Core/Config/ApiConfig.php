<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Config;

final class ApiConfig
{
    public function __construct(
        public readonly ?string $token = null,
        public readonly ?string $baseUrl = null,
    ) {}
}
