<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Identity;

use Tiden\PHPUnitReporter\Core\Exception\SignatureException;

/**
 * The case-identity rule for PHPUnit. This is the most load-bearing file in the
 * package.
 *
 *   php/v3::<normalized FQCN segments>::<normalized method name>
 *
 * ANY change to this file's output for an existing input duplicates cases in
 * every product that reports PHPUnit tests: the server keys a repository case
 * on "s:" + signature, so a changed signature forks the case and its history
 * rather than updating it. Changes go through a new version tag, never through
 * a quiet edit to the pipeline. The golden test is the enforcement.
 *
 * Two properties are deliberate:
 *
 * 1. PARAM-FREE. A data-provider row is NOT part of the identity. One test
 *    method is one Tiden case, and each data row is a separate result attempt
 *    on it carrying its values in params. This matches
 *
 *    @tiden/reporter-commons' generateSignature (param-free by design, params
 *    hashed at attempt level) and tiden-go's pkg/gotest (pkg::testpath).
 *    It reverses the earlier php/v2 bridge in qase-tms/app, which made each
 *    dataset its own case; that scheme never reached production.
 *
 * 2. NO REPOSITORY SLUG. Signatures are already product-scoped by the API path,
 *    and commons sets the precedent of not encoding the repo. A slug read from
 *    a mutable flag means renaming the repository forks every case. The cost is
 *    that two PHP repositories reporting into ONE product with an identical
 *    fully-qualified class and method would share an identity.
 *
 * Segment normalization mirrors commons' suite-segment pipeline exactly:
 * trim, backslashes to slashes, split on "::", trim, split on tab, trim,
 * collapse whitespace runs to "_", lowercase, drop empties.
 *
 * Lowercasing is ASCII-only (strtolower, not mb_strtolower): PHP class and
 * method names are ASCII in practice, PHP itself compares them case-insensitively
 * that way, and it keeps the package free of ext-mbstring.
 */
final class Signature
{
    public const VERSION = 'php/v3';

    /**
     * @param  string  $fqcn  Fully-qualified test class name, e.g. Tests\Unit\Service\FooTest
     * @param  string  $method  Test method name, e.g. testBar
     *
     * @throws SignatureException when the inputs normalize away to nothing.
     */
    public static function forMethod(string $fqcn, string $method): string
    {
        $classSegments = [];

        foreach (explode('\\', $fqcn) as $namespaceSegment) {
            array_push($classSegments, ...self::normalize($namespaceSegment));
        }

        $methodSegments = self::normalize($method);

        if ($classSegments === [] || $methodSegments === []) {
            // An empty signature is poison: the server falls through to its hash
            // branch and the case is born external_id "h:<hash>", which reporter
            // ingest can never adopt by signature afterwards. Refuse instead.
            throw new SignatureException(sprintf(
                'Refusing to build an empty signature for class "%s" method "%s": '.
                'a signature that normalizes to nothing makes the case unreachable by ingest.',
                $fqcn,
                $method,
            ));
        }

        return implode('::', [self::VERSION, ...$classSegments, ...$methodSegments]);
    }

    /**
     * The identity key the server derives from a signature, and the external_id
     * a live-doc-born case carries. Reporter ingest keys on external_id, so
     * anything upserting these cases must use exactly this prefix.
     */
    public static function externalId(string $signature): string
    {
        return 's:'.$signature;
    }

    /**
     * commons' suite-segment pipeline, applied to one raw segment. Returns zero
     * or more normalized pieces, because "::" and tab split a segment further.
     *
     * @return list<string>
     */
    public static function normalize(string $segment): array
    {
        $segment = str_replace('\\', '/', trim($segment));

        $pieces = [];

        foreach (explode('::', $segment) as $bySeparator) {
            foreach (explode("\t", trim($bySeparator)) as $byTab) {
                $collapsed = preg_replace('/\s+/', '_', trim($byTab));

                if ($collapsed === null || $collapsed === '') {
                    continue;
                }

                $pieces[] = strtolower($collapsed);
            }
        }

        return $pieces;
    }
}
