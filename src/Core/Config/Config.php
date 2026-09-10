<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Config;

final class Config
{
    /**
     * @param  array<string, string>  $statusMapping  TIDEN_STATUS_MAPPING, from=to pairs.
     */
    public function __construct(
        public readonly Mode $mode = Mode::Off,
        public readonly Mode $fallback = Mode::Off,
        public readonly bool $debug = false,
        public readonly ?string $environment = null,
        public readonly ?string $rootSuite = null,
        public readonly ?string $rootDir = null,
        public readonly array $statusMapping = [],
        public readonly ?string $productId = null,
        public readonly ApiConfig $api = new ApiConfig,
        public readonly RunConfig $run = new RunConfig,
        public readonly BatchConfig $batch = new BatchConfig,
        public readonly ReportConfig $report = new ReportConfig,
        public readonly LoggingConfig $logging = new LoggingConfig,
        public readonly ?string $stateFile = null,
    ) {}
}
