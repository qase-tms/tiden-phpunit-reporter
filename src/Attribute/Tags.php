<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Attribute;

use Attribute;

/** Free-form tags; sent as the comma-joined fields["tags"], as commons does. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Tags
{
    /** @var list<string> */
    public readonly array $tags;

    public function __construct(string ...$tags)
    {
        $this->tags = array_values($tags);
    }
}
