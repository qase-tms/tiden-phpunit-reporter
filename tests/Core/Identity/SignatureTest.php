<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core\Identity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Exception\SignatureException;
use Tiden\PHPUnitReporter\Core\Identity\Signature;

final class SignatureTest extends TestCase
{
    private const GOLDEN = __DIR__.'/../../golden/signatures.golden';

    /**
     * The signature stability lock, modelled on tiden-go's
     * TestSignatureStabilityGolden.
     *
     * If this fails, the change under review alters case identity for tests
     * that are ALREADY REPORTED. The server keys a repository case on
     * "s:" + signature, so every affected case forks: a new case is created,
     * the old one stops receiving results, and its history stops there. That is
     * not something to fix by regenerating the fixture.
     */
    public function test_signature_stability_golden(): void
    {
        $lines = [];

        foreach (self::goldenTable() as [$fqcn, $method, $expected]) {
            $lines[] = sprintf("%s\t%s\t=>\t%s", $fqcn, $method, Signature::forMethod($fqcn, $method));
        }

        $actual = implode("\n", $lines)."\n";

        $this->assertSame(
            file_get_contents(self::GOLDEN),
            $actual,
            'SIGNATURE DRIFT — this duplicates repository cases on live-doc sync. '.
            'Every already-reported case whose signature changed forks into a new case and loses its history. '.
            'If the change is intentional, bump Signature::VERSION instead of editing the fixture.',
        );
    }

    public function test_version_prefix_is_part_of_the_contract(): void
    {
        // The prefix is what makes an intentional identity change explicit and
        // greppable rather than a silent fork, so it is asserted on its own.
        $this->assertSame('php/v3', Signature::VERSION);
        $this->assertStringStartsWith('php/v3::', Signature::forMethod('FooTest', 'testBar'));
    }

    /**
     * Param-free: the whole point of the rule. A data provider's rows are
     * attempts on ONE case, so nothing about a dataset may reach this function
     * — it does not even take the argument.
     */
    public function test_identity_is_independent_of_data_provider_rows(): void
    {
        $first = Signature::forMethod('Tests\Unit\ParserTest', 'testParses');
        $second = Signature::forMethod('Tests\Unit\ParserTest', 'testParses');

        $this->assertSame($first, $second);
        $this->assertSame(2, (new \ReflectionMethod(Signature::class, 'forMethod'))->getNumberOfParameters());
    }

    /**
     * The property that replaces the old php/v2 collision detector. Under
     * param-free identity a data row may legitimately share a signature, but
     * two DISTINCT methods never may: one of the two results would be treated
     * as a retry of the other, and if the dropped one failed, the survivor
     * reads as a pass.
     */
    public function test_distinct_methods_never_collide(): void
    {
        $corpus = [
            ['Tests\Parser\ParserTest', 'testOperandIs'],
            ['Tests\Parser\ParserTest', 'testOperandIS'],
            ['Tests\Parser\Parser_Test', 'testOperandIs'],
            ['Tests\Parser\ParserTest', 'test_operand_is'],
            ['Tests\Parser\ParserTest', 'testOperand Is'],
            ['Tests\Parser\Sub\ParserTest', 'testOperandIs'],
            ['Tests\ParserTest', 'testOperandIs'],
        ];

        $signatures = [];

        foreach ($corpus as [$fqcn, $method]) {
            $signature = Signature::forMethod($fqcn, $method);

            // Case-insensitive pairs ARE expected to collide: PHP itself treats
            // class and method names case-insensitively, so those two can never
            // coexist and are the same method.
            $normalized = strtolower($fqcn.'::'.$method);
            $normalized = (string) preg_replace('/\s+/', '_', $normalized);

            if (isset($signatures[$signature])) {
                $this->assertSame(
                    $signatures[$signature],
                    $normalized,
                    sprintf('"%s::%s" collides with a genuinely different method', $fqcn, $method),
                );

                continue;
            }

            $signatures[$signature] = $normalized;
        }

        $this->assertCount(6, $signatures);
    }

    #[DataProvider('emptyIdentityProvider')]
    public function test_refuses_an_empty_signature(string $fqcn, string $method): void
    {
        // An empty signature is poison: the server falls through to its hash
        // branch, the case is born external_id "h:<hash>", and reporter ingest
        // can never adopt it by signature afterwards.
        $this->expectException(SignatureException::class);

        Signature::forMethod($fqcn, $method);
    }

    /** @return iterable<string, array{string, string}> */
    public static function emptyIdentityProvider(): iterable
    {
        yield 'empty class' => ['', 'testBar'];
        yield 'empty method' => ['FooTest', ''];
        yield 'whitespace class' => ["  \t ", 'testBar'];
        yield 'whitespace method' => ['FooTest', '   '];
        yield 'separator-only class' => ['::', 'testBar'];
    }

    public function test_external_id_carries_the_signature_branch_prefix(): void
    {
        // Ingest keys on external_id first, and a live-doc-born case carries
        // "s:" + signature. Anything upserting these rows must use exactly this.
        $this->assertSame(
            's:php/v3::footest::testbar',
            Signature::externalId(Signature::forMethod('FooTest', 'testBar')),
        );
    }

    /** @return list<array{string, string, string}> */
    private static function goldenTable(): array
    {
        $rows = [];

        foreach (file(self::GOLDEN, FILE_IGNORE_NEW_LINES) as $line) {
            $parts = explode("\t=>\t", $line, 2);
            [$fqcn, $method] = explode("\t", $parts[0], 2);
            $rows[] = [$fqcn, $method, $parts[1]];
        }

        return $rows;
    }
}
