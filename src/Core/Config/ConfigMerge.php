<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Config;

/**
 * Deep merge with "a later undefined never clobbers an earlier defined value",
 * the skipUndef customizer from commons' composeOptions.
 */
final class ConfigMerge
{
    /**
     * Later layers win. Null means "not set here", never "set to nothing".
     *
     * @param  array<string, mixed>  ...$layers
     * @return array<string, mixed>
     */
    public static function compose(array ...$layers): array
    {
        $result = [];

        foreach ($layers as $layer) {
            $result = self::mergeTwo($result, $layer);
        }

        return $result;
    }

    /**
     * Drop null leaves and the empty branches they leave behind, so that a
     * layer only carries what it actually defines.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function prune(array $values): array
    {
        $pruned = [];

        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value) && ! array_is_list($value)) {
                /** @var array<string, mixed> $value */
                $nested = self::prune($value);

                if ($nested !== []) {
                    $pruned[$key] = $nested;
                }

                continue;
            }

            $pruned[$key] = $value;
        }

        return $pruned;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private static function mergeTwo(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if ($value === null) {
                continue;
            }

            $existing = $base[$key] ?? null;

            if (is_array($value) && ! array_is_list($value) && is_array($existing)) {
                /** @var array<string, mixed> $existing */
                /** @var array<string, mixed> $value */
                $base[$key] = self::mergeTwo($existing, $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
