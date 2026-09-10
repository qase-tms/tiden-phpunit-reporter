<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Run;

use Tiden\PHPUnitReporter\Core\Client\TidenApi;
use Tiden\PHPUnitReporter\Core\Config\Config;
use Tiden\PHPUnitReporter\Core\Logging\Logger;

/**
 * Owns the run's life: who creates it, who completes it, and — as importantly —
 * when nobody should.
 */
final class RunCoordinator
{
    private ?int $runSeq = null;

    public function __construct(
        private readonly Config $config,
        private readonly TidenApi $api,
        private readonly StateStore $state,
        private readonly Logger $logger,
        private readonly int $pid,
    ) {}

    /**
     * Adopt TIDEN_RUN_ID if it is set, otherwise create a run — exactly once
     * across every ParaTest worker, because the decision happens under the
     * state file's lock.
     */
    public function start(): int
    {
        if ($this->runSeq !== null) {
            return $this->runSeq;
        }

        $this->runSeq = $this->state->startRun($this->pid, function (): int {
            if ($this->config->run->id !== null) {
                $this->logger->debug(sprintf('adopting externally created run %d', $this->config->run->id));

                return $this->config->run->id;
            }

            $seq = $this->api->createRun($this->createRunBody());
            $this->logger->info(sprintf('created test run %d', $seq));

            return $seq;
        });

        // Parity with commons: a nested runner spawned from this process
        // inherits the run instead of opening a second one.
        putenv('TIDEN_RUN_ID='.$this->runSeq);

        return $this->runSeq;
    }

    /**
     * @return array<string, mixed> CreateTestRunBody
     */
    public function createRunBody(): array
    {
        $body = array_filter([
            'title' => $this->config->run->title,
            'description' => $this->config->run->description,
            'environment' => $this->config->environment,
            'branch' => $this->config->run->branch,
            'buildSha' => $this->config->run->buildSha,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        $body['startedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        $body['clientMeta'] = [
            'framework' => 'phpunit',
            'reporter' => 'tiden/phpunit-reporter',
        ];

        return $body;
    }

    /**
     * Decide and act on completion. Three things can stop a run being
     * completed, and each of them is louder than a green run would have been:
     * an explicit run.complete=false, a worker that died, and a run in which no
     * result carried a file_path.
     */
    public function finish(int $resolvedPaths, int $omittedPaths): FinishDecision
    {
        $decision = $this->state->finish($this->pid, $resolvedPaths, $omittedPaths);

        if ($decision->isAbandoned()) {
            $this->logger->error(sprintf(
                'run %s is NOT being completed: worker process(es) %s exited without reporting, so their '.
                'results are missing. The run is left open on purpose — completing it would present a '.
                'partial run as a finished one.',
                (string) ($this->runSeq ?? '?'),
                implode(', ', $decision->deadWorkers),
            ));
            $this->state->discard();

            return $decision;
        }

        if (! $decision->shouldComplete()) {
            return $decision;
        }

        try {
            if (! $this->config->run->complete) {
                $this->logger->debug('run.complete is false; leaving completion to the orchestrator');

                return $decision;
            }

            if ($decision->everyPathUnresolved()) {
                $this->logger->error(sprintf(
                    'run %s is NOT being completed: not one of %d reported test files resolved to a path '.
                    'under the configured root "%s", so every case in this run is unlinkable from its '.
                    'requirements. Set TIDEN_ROOT_DIR to the directory your test paths are relative to '.
                    '(inside a container that is usually the mount point, not the host checkout).',
                    (string) ($this->runSeq ?? '?'),
                    $decision->omittedPaths,
                    $this->config->rootDir ?? (getcwd() ?: '.'),
                ));

                return $decision;
            }

            if ($this->runSeq !== null) {
                $this->api->completeRun($this->runSeq);
                $this->logger->info(sprintf('completed test run %d', $this->runSeq));
            }
        } finally {
            $this->state->discard();
        }

        return $decision;
    }

    public function runSeq(): ?int
    {
        return $this->runSeq;
    }
}
