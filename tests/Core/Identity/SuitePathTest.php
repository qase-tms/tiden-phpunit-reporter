<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core\Identity;

use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Identity\Signature;
use Tiden\PHPUnitReporter\Core\Identity\SuitePath;

final class SuitePathTest extends TestCase
{
    public function test_is_the_namespace_chain_root_to_leaf(): void
    {
        $titles = array_map(
            static fn ($segment): string => $segment->title,
            SuitePath::forClass('Tests\Unit\Service\FooTest'),
        );

        $this->assertSame(['Tests', 'Unit', 'Service', 'FooTest'], $titles);
    }

    public function test_the_root_suite_is_prepended_not_substituted(): void
    {
        $titles = array_map(
            static fn ($segment): string => $segment->title,
            SuitePath::forClass('Tests\FooTest', 'PHPUnit tests'),
        );

        $this->assertSame(['PHPUnit tests', 'Tests', 'FooTest'], $titles);
    }

    public function test_segments_keep_their_original_casing_because_they_are_displayed(): void
    {
        $this->assertSame('FooTest', SuitePath::forClass('Tests\FooTest')[1]->title);
    }

    /**
     * The rule that keeps identity out of reach of presentation. If the root
     * suite reached the signature, setting TIDEN_ROOT_SUITE in CI would fork
     * the history of every case in the product.
     */
    public function test_the_root_suite_does_not_affect_identity(): void
    {
        $withRoot = SuitePath::forClass('Tests\FooTest', 'PHPUnit tests');
        $withoutRoot = SuitePath::forClass('Tests\FooTest');

        $this->assertNotSame(count($withRoot), count($withoutRoot));
        $this->assertSame(
            Signature::forMethod('Tests\FooTest', 'testBar'),
            Signature::forMethod('Tests\FooTest', 'testBar'),
        );
    }

    public function test_a_blank_root_suite_adds_no_segment(): void
    {
        $this->assertCount(2, SuitePath::forClass('Tests\FooTest', '   '));
    }
}
