<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Config;

/**
 * Reporting modes, mirroring @tiden/reporter-commons' ModeEnum.
 *
 * `Off` is the effective default: a developer who has never heard of Tiden runs
 * the suite and nothing happens.
 */
enum Mode: string
{
    case Tiden = 'tiden';
    case Report = 'report';
    case Off = 'off';

    public static function tryFromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }
}
