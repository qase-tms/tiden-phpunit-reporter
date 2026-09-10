<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Attribute;

use Attribute;

/**
 * An arbitrary custom field on the result.
 *
 * "file_path" is reserved: the reporter derives it from the test's real file and
 * it is the requirement<->test join key, so a hand-written one is ignored rather
 * than allowed to fabricate a link.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Field
{
    public function __construct(
        public readonly string $name,
        public readonly string $value,
    ) {}
}
