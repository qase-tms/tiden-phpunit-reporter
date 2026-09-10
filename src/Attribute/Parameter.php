<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Attribute;

use Attribute;

/**
 * An attempt-level parameter.
 *
 * Parameters describe THIS attempt, not the case: two attempts with different
 * parameters are the same case, which is what makes the param-free signature
 * consistent rather than lossy.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Parameter
{
    public function __construct(
        public readonly string $name,
        public readonly string $value,
    ) {}
}
