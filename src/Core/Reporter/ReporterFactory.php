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
use Tiden\PHPUnitReporter\Core\Identity\RepositoryResolver;
use Tiden\PHPUnitReporter\Core\Logging\Logger;
use Tiden\PHPUnitReporter\Core\Run\RunCoordinator;
use Tiden\PHPUnitReporter\Core\Run\StateStore;
use Tiden\PHPUnitReporter\Core\Transform\ResultTransformer;

final class ReporterFactory
{
    public function __construct(
        private readonly ?Transport $transport = null,
        private readonly ?int $pid = null,
        /** @var array<string, string>|null CI variables for the repository lookup; the process environment when null. */
        private readonly ?array $env = null,
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

        if ($mode === Mode::Off) {
            return new NullReporter;
        }

        // Only a reporter that will send something looks for the repository:
        // a disabled one must not read the file system at all.
        $repository = $this->repository($config, $filePaths, $logger);
        $transformer = new ResultTransformer($config->statusMapping, $repository);

        return match ($mode) {
            Mode::Report => new FileReporter((string) $config->report->path, $transformer, $logger),
            Mode::Tiden => $this->createRunReporter($config, $transformer, $filePaths, $logger, $repository),
        };
    }

    /**
     * The repository the results belong to, or null with one warning.
     *
     * Without it the identity parts are left out and the legacy signature is the
     * whole identity, exactly as before this existed. That still works, so it is
     * a warning and not an error, but it must not be silent: the user is giving
     * up the rename-proof identity without knowing.
     */
    private function repository(Config $config, FilePathResolver $filePaths, Logger $logger): ?string
    {
        $repository = RepositoryResolver::resolve(
            $config->repository,
            $this->env ?? RepositoryResolver::environment(),
            [$filePaths->root()],
        );

        if ($repository === null) {
            $logger->warningOnce(
                'repository',
                'could not tell which git repository these tests belong to, so results are sent without '.
                'their identity parts (the signature still identifies each case). Set TIDEN_REPOSITORY, '.
                'for example https://github.com/<owner>/<repo>, or run inside a checkout that has an '.
                '"origin" remote.',
            );
        }

        return $repository;
    }

    /**
     * The commit checked out under the root directory, the run's build SHA when
     * neither TIDEN_BUILD_SHA nor run.buildSha sets one. A Quality Gate that
     * counts only a release's own commit cannot see a run without it.
     */
    private function headCommit(Config $config, FilePathResolver $filePaths, Logger $logger): ?string
    {
        if ($config->run->buildSha !== null) {
            return null;
        }

        $commit = RepositoryResolver::headCommit([$filePaths->root()]);

        if ($commit !== null) {
            $logger->debug(sprintf('build SHA %s read from the git HEAD of the checkout', $commit));
        }

        return $commit;
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
        ?string $repository,
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
            repository: $repository,
            headCommit: $this->headCommit($config, $filePaths, $logger),
        );

        return new RunReporter($config, $api, $coordinator, $transformer, $filePaths, $logger);
    }
}
