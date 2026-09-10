<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Examples;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Attribute\Field;
use Tiden\PHPUnitReporter\Attribute\Suite;
use Tiden\PHPUnitReporter\Attribute\Tags;
use Tiden\PHPUnitReporter\Attribute\Title;
use Tiden\PHPUnitReporter\Tiden;

/**
 * The suite the golden integration test runs. Every outcome the reporter can
 * report appears here exactly once, so the golden file is a readable record of
 * what each PHPUnit event turns into.
 *
 * This is not part of the package's own test suite — it is deliberately
 * expected to fail.
 */
final class ExampleTest extends TestCase
{
    public function test_it_passes(): void
    {
        $this->assertTrue(true);
    }

    #[Title('A named case')]
    #[Tags('smoke', 'billing')]
    #[Field('layer', 'unit')]
    public function test_it_carries_metadata(): void
    {
        Tiden::comment('a comment from inside the test');
        $this->assertSame(1, 1);
    }

    public function test_it_fails_an_assertion(): void
    {
        $this->assertSame('expected', 'actual');
    }

    public function test_it_throws_before_asserting(): void
    {
        throw new \TypeError('production code exploded');
    }

    public function test_it_is_skipped(): void
    {
        $this->markTestSkipped('not applicable here');
    }

    /**
     * A #[Suite] REPLACES the namespace-derived path rather than extending it,
     * matching the Playwright reporter. It moves where the case is displayed
     * and never changes what case it is.
     */
    #[Suite('Billing')]
    #[Suite('Invoices')]
    public function test_it_is_filed_under_a_custom_suite(): void
    {
        $this->assertTrue(true);
    }

    /**
     * Two rows, ONE case. Param-free identity means both attempts share a
     * signature and are told apart by their params.
     */
    #[DataProvider('rowProvider')]
    public function test_it_runs_once_per_row(int $input, int $expected): void
    {
        $this->assertSame($expected, $input * 2);
    }

    /** @return iterable<string, array{int, int}> */
    public static function rowProvider(): iterable
    {
        yield 'doubles two' => [2, 4];
        yield 'doubles three' => [3, 6];
    }
}
