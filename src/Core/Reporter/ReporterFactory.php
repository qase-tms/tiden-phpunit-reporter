<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Reporter;

use Tiden\PHPUnitReporter\Core\Client\CurlTransport;
use Tiden\PHPUnitReporter\Core\Client\TidenApi;
use Tiden\PHPUnitReporter\Core\Client\Transport;
use Tiden\PHPUnitReporter\Core\Config\Config;
use Tiden\PHPUnitReporter\Core\Config\ConfigResolver;
use Tiden\PHPUnitReporter\Core\Config\Mode;
use Tiden\PHPUnitReporter\Core\Config\Resolution;
use Tiden\PHPUnitReporter\Core\Identity\FilePathResolver;
use Tiden\PHPUnitReporter\Core\Logging\Logger;
use Tiden\PHPUnitReporter\Core\Run\RunCoordinator;
use Tiden\PHPUnitReporter\Core\Run\StateStore;
use Tiden\PHPUnitReporter\Core\Transform\ResultTransformer;

final class ReporterFactory
{
    public function __construct(
        private readonly ?Transport $transport = null,
        private readonly ?int $pid = null,
    ) {}

    /**
     * Turn a resolved configuration into the reporter it asks for.
     *
     * A disabled reporter says so once, at info level, but only when the user
     * showed some intent by setting a TIDEN_* variable or turning on debug.
     * Announcing unconditionally would print a line per ParaTest worker on
     * every local run for developers who do not use Tiden at all; announcing
     * never is how people lose an afternoon to a reporter that was quietly
     * inert the whole time.
     */
    public function create(Resolution $resolution, Logger $logger, FilePathResolver $filePaths): InternalReporter
    {
        $config = $resolution->config;
        $mode = $config->mode;

        if ($resolution->disabled !== null) {
            // TIDEN_FALLBACK: when the primary mode cannot run, a viable
            // fallback still captures the results rather than dropping them.
            $fallback = $this->viableFallback($config);

            if ($fallback === null) {
                if ($resolution->announce) {
                    $logger->info($resolution->disabled->message());
                }

                return new NullReporter;
            }

            $logger->info(sprintf(
                'falling back to "%s" mode, because %s.',
                $fallback->value,
                $resolution->disabled->reason,
            ));

            $mode = $fallback;
        }

        $transformer = new ResultTransformer($config->statusMapping);

        return match ($mode) {
            Mode::Report => new FileReporter((string) $config->report->path, $transformer, $logger),
            Mode::Tiden => $this->createRunReporter($config, $transformer, $filePaths, $logger),
            Mode::Off => new NullReporter,
        };
    }

    /** The configured fallback, but only if its own requirements are met. */
    private function viableFallback(Config $config): ?Mode
    {
        if ($config->fallback === Mode::Off || $config->fallback === $config->mode) {
            return null;
        }

        return ConfigResolver::disabledFor($config, $config->fallback) === null ? $config->fallback : null;
    }

    private function createRunReporter(
        Config $config,
        ResultTransformer $transformer,
        FilePathResolver $filePaths,
        Logger $logger,
    ): InternalReporter {
        $api = new TidenApi(
            baseUrl: (string) $config->api->baseUrl,
            token: (string) $config->api->token,
            productId: (string) $config->productId,
            transport: $this->transport ?? new CurlTransport,
            logger: $logger,
        );

        $coordinator = new RunCoordinator(
            config: $config,
            api: $api,
            state: new StateStore(StateStore::defaultPath($config->stateFile)),
            logger: $logger,
            pid: $this->pid ?? (getmypid() ?: 0),
        );

        return new RunReporter($config, $api, $coordinator, $transformer, $filePaths, $logger);
    }
}
