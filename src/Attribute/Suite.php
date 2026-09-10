<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Attribute;

use Attribute;

/**
 * Where the case is displayed in the suite tree, replacing the namespace-derived
 * path.
 *
 * Display only: this never changes the case's signature. Playwright lets a suite
 * annotation replace the identity path, which makes identity editable by an
 * annotation — a rename then forks the case's history. We do not copy that.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Suite
{
    public function __construct(public readonly string $title) {}
}
