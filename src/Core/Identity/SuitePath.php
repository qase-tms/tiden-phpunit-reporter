<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Identity;

use Tiden\PHPUnitReporter\Core\Model\SuiteSegment;

/**
 * The DISPLAY tree a case hangs under: root suite, then one segment per
 * namespace part, then the short class name. The method name is deliberately
 * excluded — it is the case title, not a suite.
 *
 * Segments are raw, not normalized: this is what a human reads in the UI, so
 * "FooTest" stays "FooTest" while the signature says "footest".
 *
 * This never feeds Signature. A root suite or a #[Suite] attribute changes
 * where a case is displayed and must never change what case it IS — otherwise
 * setting TIDEN_ROOT_SUITE in CI would fork the history of every case.
 *
 * No externalId is emitted, matching every JS reporter: an externalId derived
 * from the same titles buys no rename-safety, and a wrong one is a permanent
 * duplicate.
 */
final class SuitePath
{
    /**
     * @return list<SuiteSegment>
     */
    public static function forClass(string $fqcn, ?string $rootSuite = null): array
    {
        $segments = [];

        if ($rootSuite !== null && trim($rootSuite) !== '') {
            $segments[] = new SuiteSegment(trim($rootSuite));
        }

        foreach (explode('\\', $fqcn) as $part) {
            $part = trim($part);

            if ($part !== '') {
                $segments[] = new SuiteSegment($part);
            }
        }

        return $segments;
    }
}
