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

    /** True when this worker joined a run another worker had already closed. */
    private bool $joinedClosedRun = false;

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

        $handle = $this->state->startRun($this->pid, function (): int {
            if ($this->config->run->id !== null) {
                $this->logger->debug(sprintf('adopting externally created run %d', $this->config->run->id));

                return $this->config->run->id;
            }

            $seq = $this->api->createRun($this->createRunBody());
            $this->logger->info(sprintf('created test run %d', $seq));

            return $seq;
        });

        $this->runSeq = $handle->runSeq;

        if ($handle->alreadyCompleted) {
            $this->joinedClosedRun = true;

            // This worker started after another decided the run was over. Its
            // results will be rejected ("results are locked"), which is the
            // loud outcome; the silent one would be a second run holding half
            // the suite.
            $this->logger->error(sprintf(
                'joined run %d, but it was already completed by another worker before this process started, '.
                'so its results cannot be recorded. Set TIDEN_RUN_COMPLETE=false and complete the run once '.
                'the whole suite has finished.',
                $this->runSeq,
            ));
        }

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

            return $decision;
        }

        if (! $decision->shouldComplete()) {
            return $decision;
        }

        try {
            if ($this->joinedClosedRun) {
                // Already reported as an error when this worker joined; saying
                // "completed test run N" on top of that would contradict it.
                return $decision;
            }

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
            // Recorded, never deleted: a worker that starts after this point
            // must adopt the run rather than open a second one.
            $this->state->markCompleted();
        }

        return $decision;
    }

    public function runSeq(): ?int
    {
        return $this->runSeq;
    }
}
