<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Model;

/** The statuses ResultExecution.status accepts, per public-api/v1/openapi.yaml. */
enum Status: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Blocked = 'blocked';
    case Skipped = 'skipped';
    case Invalid = 'invalid';

    public static function tryFromString(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    /** Ranked worst-first, so a fold over attempts can pick the one that matters. */
    public function severity(): int
    {
        return match ($this) {
            self::Failed => 5,
            self::Invalid => 4,
            self::Blocked => 3,
            self::Skipped => 2,
            self::Passed => 1,
        };
    }
}
