<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Utf8;

final class Utf8Test extends TestCase
{
    #[DataProvider('truncateCases')]
    public function test_truncate_never_splits_a_character(string $value, int $maxBytes, string $expected): void
    {
        $actual = Utf8::truncate($value, $maxBytes);

        $this->assertSame($expected, $actual);
        $this->assertLessThanOrEqual($maxBytes, strlen($actual));
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function truncateCases(): iterable
    {
        yield 'shorter_than_the_budget_is_untouched' => ['abc', 10, 'abc'];
        yield 'exactly_the_budget_is_untouched' => ['abc', 3, 'abc'];
        yield 'ascii_is_cut_at_the_budget' => ['abcdef', 3, 'abc'];
        yield 'a_two_byte_character_split_in_half_is_dropped' => ["ab\u{00e9}", 3, 'ab'];
        yield 'a_two_byte_character_that_fits_is_kept' => ["ab\u{00e9}", 4, "ab\u{00e9}"];
        yield 'a_three_byte_character_split_is_dropped' => ["a\u{20ac}", 3, 'a'];
        yield 'a_four_byte_character_split_is_dropped' => ["a\u{1f600}", 4, 'a'];
        yield 'a_four_byte_character_that_fits_is_kept' => ["a\u{1f600}", 5, "a\u{1f600}"];
        yield 'a_budget_of_zero_is_empty' => ['abc', 0, ''];
        yield 'a_budget_smaller_than_the_first_character_is_empty' => ["\u{20ac}", 2, ''];
    }

    /**
     * The property that matters: whatever comes back must be encodable. A cut
     * through a multi-byte sequence used to make json_encode refuse the whole
     * batch the value was travelling in.
     */
    #[DataProvider('budgets')]
    public function test_every_truncation_of_a_multibyte_string_stays_encodable(int $maxBytes): void
    {
        $value = str_repeat("a\u{00e9}\u{20ac}\u{1f600}", 10);

        $truncated = Utf8::truncate($value, $maxBytes);

        $this->assertSame(1, preg_match('//u', $truncated), 'truncation produced invalid UTF-8');
        $this->assertIsString(json_encode(['data' => $truncated], JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{int}> */
    public static function budgets(): iterable
    {
        foreach (range(0, 40) as $budget) {
            yield 'budget_'.$budget => [$budget];
        }
    }
}
