<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Attribute;

/** Everything the attributes on a test class and method add up to. */
final class Metadata
{
    /**
     * @param  list<string>  $suites
     * @param  list<string>  $tags
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $parameters
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly array $suites = [],
        public readonly array $tags = [],
        public readonly array $fields = [],
        public readonly array $parameters = [],
    ) {}
}
