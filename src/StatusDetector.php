<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter;

use PHPUnit\Event\Code\Throwable;
use Tiden\PHPUnitReporter\Core\Model\Status;

/**
 * Which Tiden status a PHPUnit failure actually means.
 *
 * PHPUnit reports "the test did not pass" through several events, and they do
 * not mean the same thing to a quality gate: an assertion that did not hold is
 * a genuine FAILED, while a TypeError thrown from production code before any
 * assertion ran is an INVALID test — broken, not failing.
 */
final class StatusDetector
{
    private const ASSERTION_CLASSES = [
        'PHPUnit\Framework\AssertionFailedError',
        'PHPUnit\Framework\ExpectationFailedException',
    ];

    public static function forFailure(Throwable $throwable): Status
    {
        return self::isAssertionFailure($throwable->className()) ? Status::Failed : Status::Invalid;
    }

    private static function isAssertionFailure(string $className): bool
    {
        foreach (self::ASSERTION_CLASSES as $assertionClass) {
            if ($className === $assertionClass || is_subclass_of($className, $assertionClass)) {
                return true;
            }
        }

        return false;
    }
}
