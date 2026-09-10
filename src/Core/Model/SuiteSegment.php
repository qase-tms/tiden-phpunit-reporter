<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Model;

final class SuiteSegment
{
    public function __construct(public readonly string $title) {}
}
