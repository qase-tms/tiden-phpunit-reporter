<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Attribute;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Tiden\PHPUnitReporter\Core\Logging\Logger;

/**
 * Reads Tiden attributes off a test class and method.
 *
 * Class-level attributes are read first so that a method-level #[Title] wins
 * while #[Suite], #[Tags] and #[Field] accumulate. Reflection failures are
 * logged and degrade to empty metadata: a missing #[Title] should never take a
 * test run down.
 *
 * Results are memoised per class::method — under ParaTest with coverage
 * enabled, reflecting the same method repeatedly is measurable.
 *
 * @phpstan-type ClassString class-string
 */
final class AttributeReader
{
    /** @var array<string, Metadata> */
    private array $cache = [];

    public function __construct(private readonly ?Logger $logger = null) {}

    public function read(string $className, string $methodName): Metadata
    {
        $key = $className.'::'.$methodName;

        return $this->cache[$key] ??= $this->readUncached($className, $methodName);
    }

    private function readUncached(string $className, string $methodName): Metadata
    {
        try {
            /** @var class-string $className */
            $class = new ReflectionClass($className);
            $attributes = $this->instantiate($class->getAttributes());

            if ($class->hasMethod($methodName)) {
                $method = new ReflectionMethod($className, $methodName);
                $attributes = [...$attributes, ...$this->instantiate($method->getAttributes())];
            }
        } catch (ReflectionException $e) {
            $this->logger?->debug(sprintf('could not read attributes of %s::%s: %s', $className, $methodName, $e->getMessage()));

            return new Metadata;
        }

        $title = null;
        $suites = [];
        $tags = [];
        $fields = [];
        $parameters = [];

        foreach ($attributes as $attribute) {
            switch (true) {
                case $attribute instanceof Title:
                    $title = $attribute->title;
                    break;
                case $attribute instanceof Suite:
                    $suites[] = $attribute->title;
                    break;
                case $attribute instanceof Tags:
                    $tags = [...$tags, ...$attribute->tags];
                    break;
                case $attribute instanceof Field:
                    // file_path is derived from the real file; a hand-written
                    // one could only ever fabricate a requirement link.
                    if ($attribute->name !== 'file_path') {
                        $fields[$attribute->name] = $attribute->value;
                    }
                    break;
                case $attribute instanceof Parameter:
                    $parameters[$attribute->name] = $attribute->value;
                    break;
            }
        }

        return new Metadata(
            title: $title,
            suites: $suites,
            tags: array_values(array_unique($tags)),
            fields: $fields,
            parameters: $parameters,
        );
    }

    /**
     * @param  list<\ReflectionAttribute<object>>  $reflected
     * @return list<object>
     */
    private function instantiate(array $reflected): array
    {
        $instances = [];

        foreach ($reflected as $attribute) {
            if (! str_starts_with($attribute->getName(), __NAMESPACE__.'\\')) {
                continue;
            }

            try {
                $instances[] = $attribute->newInstance();
            } catch (\Throwable $e) {
                $this->logger?->debug(sprintf('could not instantiate attribute %s: %s', $attribute->getName(), $e->getMessage()));
            }
        }

        return $instances;
    }
}
