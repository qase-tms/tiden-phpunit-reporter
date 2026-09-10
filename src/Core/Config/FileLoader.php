<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Config;

use Tiden\PHPUnitReporter\Core\Exception\ConfigException;

/**
 * Loads tiden.config.json from the working directory.
 *
 * Same filename and same nested shape as commons' config file, so a repo that
 * already reports from JS can point PHP at the file it already has.
 */
final class FileLoader
{
    public const FILENAME = 'tiden.config.json';

    /**
     * A missing file is not an error — it is the common case. Anything else is,
     * because a config file that exists and cannot be read is a typo the user
     * wants to hear about, not a silent fallback to no config.
     *
     * @return array<string, mixed>
     *
     * @throws ConfigException
     */
    public function load(?string $directory = null): array
    {
        $path = rtrim($directory ?? (getcwd() ?: '.'), '/').'/'.self::FILENAME;

        if (! is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new ConfigException(sprintf('Cannot read config file "%s".', $path));
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigException(sprintf('Invalid JSON in config file "%s": %s', $path, $e->getMessage()), previous: $e);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new ConfigException(sprintf('Config file "%s" must contain a JSON object.', $path));
        }

        /** @var array<string, mixed> $decoded */
        return ConfigMerge::prune($decoded);
    }
}
