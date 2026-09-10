<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Config;

final class Resolution
{
    public function __construct(
        public readonly Config $config,
        public readonly ?DisabledReason $disabled = null,
        public readonly bool $announce = false,
    ) {}

    public function isEnabled(): bool
    {
        return $this->disabled === null;
    }
}
