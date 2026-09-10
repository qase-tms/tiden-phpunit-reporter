<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Attribute;

use Attribute;

/** Human-readable case title, replacing the method name. Display only. */
#[Attribute(Attribute::TARGET_METHOD)]
final class Title
{
    public function __construct(public readonly string $title) {}
}
