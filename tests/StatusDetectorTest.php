<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests;

use PHPUnit\Event\Code\Throwable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Model\Status;
use Tiden\PHPUnitReporter\StatusDetector;

final class StatusDetectorTest extends TestCase
{
    /**
     * "Failed" and "invalid" are not interchangeable to a quality gate: an
     * assertion that did not hold is a real failure of the thing under test,
     * while a TypeError thrown before any assertion ran means the test itself
     * is broken. Collapsing the two hides broken tests inside the failure count.
     */
    #[DataProvider('throwableProvider')]
    public function test_separates_failed_assertions_from_broken_tests(string $className, Status $expected): void
    {
        $this->assertSame($expected, StatusDetector::forFailure($this->throwable($className)));
    }

    /** @return iterable<string, array{string, Status}> */
    public static function throwableProvider(): iterable
    {
        yield 'expectation failed' => ['PHPUnit\Framework\ExpectationFailedException', Status::Failed];
        yield 'assertion failed' => ['PHPUnit\Framework\AssertionFailedError', Status::Failed];
        yield 'incomplete test (subclasses AssertionFailedError)' => ['PHPUnit\Framework\IncompleteTestError', Status::Failed];
        yield 'type error from production code' => ['TypeError', Status::Invalid];
        yield 'runtime error' => ['RuntimeException', Status::Invalid];
        yield 'unknown class name' => ['Some\Vendor\NotLoadedException', Status::Invalid];
    }

    private function throwable(string $className): Throwable
    {
        return new Throwable($className, 'message', 'description', 'stack trace', null);
    }
}
