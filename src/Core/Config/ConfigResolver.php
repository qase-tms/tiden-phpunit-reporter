<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Config;

/**
 * Resolves the effective configuration from every layer.
 *
 * Precedence, highest first: environment > tiden.config.json > PHPUnit
 * <extension><parameter> elements. Environment wins because CI sets it, and
 * the point of the file is to be the thing CI overrides.
 */
final class ConfigResolver
{
    public function __construct(
        private readonly EnvLoader $envLoader = new EnvLoader,
        private readonly FileLoader $fileLoader = new FileLoader,
    ) {}

    /**
     * @param  array<string, string>  $env
     * @param  array<string, mixed>  $parameters  PHPUnit extension parameters, already flattened.
     */
    public function resolve(array $env, array $parameters = [], ?string $directory = null): Resolution
    {
        $composed = ConfigMerge::compose(
            ConfigMerge::prune($parameters),
            $this->fileLoader->load($directory),
            $this->envLoader->load($env),
        );

        $config = $this->build($composed);
        $announce = $this->envLoader->hasAnyTidenVariable($env) || $config->debug;

        return new Resolution($config, self::disabledFor($config), $announce);
    }

    /** @param array<string, mixed> $raw */
    public function build(array $raw): Config
    {
        /** @var array<string, mixed> $tiden */
        $tiden = self::section($raw, 'tiden');
        /** @var array<string, mixed> $api */
        $api = self::section($tiden, 'api');
        /** @var array<string, mixed> $run */
        $run = self::section($tiden, 'run');
        /** @var array<string, mixed> $batch */
        $batch = self::section($tiden, 'batch');
        /** @var array<string, mixed> $local */
        $local = self::section(self::section(self::section($raw, 'report'), 'connections'), 'local');
        /** @var array<string, mixed> $logging */
        $logging = self::section($raw, 'logging');

        /** @var array<string, string> $statusMapping */
        $statusMapping = is_array($raw['statusMapping'] ?? null) ? $raw['statusMapping'] : [];

        return new Config(
            mode: Mode::tryFromString(self::string($raw, 'mode')) ?? Mode::Off,
            fallback: Mode::tryFromString(self::string($raw, 'fallback')) ?? Mode::Off,
            debug: self::bool($raw, 'debug') ?? false,
            environment: self::string($raw, 'environment'),
            rootSuite: self::string($raw, 'rootSuite'),
            rootDir: self::string($raw, 'rootDir'),
            statusMapping: $statusMapping,
            productId: self::string($tiden, 'product'),
            api: new ApiConfig(
                token: self::string($api, 'token'),
                baseUrl: self::string($api, 'baseUrl'),
            ),
            run: new RunConfig(
                id: self::int($run, 'id'),
                title: self::string($run, 'title'),
                description: self::string($run, 'description'),
                // Undefined means complete; only an explicit false hands
                // completion to an orchestrator (commons' RunService rule).
                complete: self::bool($run, 'complete') ?? true,
                branch: self::string($run, 'branch'),
                buildSha: self::string($run, 'buildSha'),
            ),
            batch: new BatchConfig(self::int($batch, 'size')),
            report: new ReportConfig(
                path: self::string($local, 'path'),
                format: self::string($local, 'format') ?? 'json',
            ),
            logging: new LoggingConfig(
                console: self::bool($logging, 'console') ?? true,
                file: self::bool($logging, 'file') ?? (self::bool($raw, 'debug') ?? false),
            ),
            stateFile: self::string($raw, 'stateFile'),
        );
    }

    /**
     * The settings that must be present for the chosen mode, checked in the
     * same order commons checks them so the first thing a user is told to fix
     * is the same on both sides.
     */
    public static function disabledFor(Config $config, ?Mode $mode = null): ?DisabledReason
    {
        return match ($mode ?? $config->mode) {
            Mode::Off => DisabledReason::modeOff(),
            Mode::Report => $config->report->path === null ? DisabledReason::missingReportPath() : null,
            Mode::Tiden => match (true) {
                $config->api->token === null => DisabledReason::missingSetting('tiden.api.token', 'TIDEN_API_TOKEN'),
                $config->productId === null => DisabledReason::missingSetting('tiden.product', 'TIDEN_PRODUCT_ID'),
                $config->api->baseUrl === null => DisabledReason::missingSetting('tiden.api.baseUrl', 'TIDEN_BASE_URL'),
                default => null,
            },
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function section(array $raw, string $key): array
    {
        $value = $raw[$key] ?? null;

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $raw */
    private static function string(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $raw */
    private static function int(array $raw, string $key): ?int
    {
        $value = $raw[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int) trim($value);
        }

        return null;
    }

    /** @param array<string, mixed> $raw */
    private static function bool(array $raw, string $key): ?bool
    {
        $value = $raw[$key] ?? null;

        return is_bool($value) ? $value : null;
    }
}
