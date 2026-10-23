<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Identity;

/**
 * The parts of a test's identity, as the server's ResultCreate.identity wants
 * them. The repository is not here: it is the same for the whole run and is
 * added when the result is turned into a request body.
 *
 *   scope = [namespace segments..., class]    raw case, as written in the code
 *   name  = [method]                          never the data-set
 *
 * The server builds the case key from these parts. This class does no
 * normalisation on purpose: lowercasing and the like are the server's rule, so
 * that a change to it never needs a reporter release. The legacy signature
 * (Signature) is unrelated and stays as it is.
 */
final class IdentityParts
{
    public const LANGUAGE = 'php';

    /**
     * @param  list<string>  $scope
     * @param  list<string>  $name
     */
    public function __construct(
        public readonly array $scope,
        public readonly array $name,
        public readonly string $language = self::LANGUAGE,
    ) {}

    /** Null when the class or the method is empty: parts with a hole in them would be refused anyway. */
    public static function forMethod(string $fqcn, string $method): ?self
    {
        $scope = array_values(array_filter(
            explode('\\', $fqcn),
            static fn (string $segment): bool => trim($segment) !== '',
        ));

        if ($scope === [] || trim($method) === '') {
            return null;
        }

        return new self($scope, [$method]);
    }

    /**
     * @return array{language: string, repository: string, scope: list<string>, name: list<string>}
     */
    public function toWire(string $repository): array
    {
        return [
            'language' => $this->language,
            'repository' => $repository,
            'scope' => $this->scope,
            'name' => $this->name,
        ];
    }
}
