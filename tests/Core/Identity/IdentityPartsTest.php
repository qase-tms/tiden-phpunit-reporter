<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core\Identity;

use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Identity\IdentityParts;

final class IdentityPartsTest extends TestCase
{
    public function test_scope_is_the_namespace_segments_then_the_class_and_name_is_the_method(): void
    {
        $parts = IdentityParts::forMethod('Tests\\Unit\\Service\\FooTest', 'testBar');

        $this->assertNotNull($parts);
        $this->assertSame('php', $parts->language);
        $this->assertSame(['Tests', 'Unit', 'Service', 'FooTest'], $parts->scope);
        $this->assertSame(['testBar'], $parts->name);
    }

    /** Raw: lowercasing and the like belong to the server, not to a reporter release. */
    public function test_case_and_underscores_are_kept_as_written(): void
    {
        $parts = IdentityParts::forMethod('App\\Tests\\HTTPClientTest', 'test_It_Retries_ON_503');

        $this->assertNotNull($parts);
        $this->assertSame(['App', 'Tests', 'HTTPClientTest'], $parts->scope);
        $this->assertSame(['test_It_Retries_ON_503'], $parts->name);
    }

    public function test_a_leading_backslash_and_a_global_class_are_handled(): void
    {
        $this->assertSame(['FooTest'], IdentityParts::forMethod('\\FooTest', 'testBar')?->scope);
        $this->assertSame(['FooTest'], IdentityParts::forMethod('FooTest', 'testBar')?->scope);
    }

    public function test_parts_with_a_hole_are_not_built(): void
    {
        $this->assertNull(IdentityParts::forMethod('', 'testBar'));
        $this->assertNull(IdentityParts::forMethod('\\', 'testBar'));
        $this->assertNull(IdentityParts::forMethod('FooTest', ' '));
    }

    public function test_the_wire_shape_has_exactly_the_four_documented_keys(): void
    {
        $wire = IdentityParts::forMethod('A\\FooTest', 'testBar')?->toWire('https://github.com/acme/shop');

        $this->assertSame([
            'language' => 'php',
            'repository' => 'https://github.com/acme/shop',
            'scope' => ['A', 'FooTest'],
            'name' => ['testBar'],
        ], $wire);
    }
}
