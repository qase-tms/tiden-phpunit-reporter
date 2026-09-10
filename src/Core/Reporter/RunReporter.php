<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Reporter;

use Tiden\PHPUnitReporter\Core\Client\TidenApi;
use Tiden\PHPUnitReporter\Core\Config\Config;
use Tiden\PHPUnitReporter\Core\Exception\TidenException;
use Tiden\PHPUnitReporter\Core\Identity\FilePathResolver;
use Tiden\PHPUnitReporter\Core\Logging\Logger;
use Tiden\PHPUnitReporter\Core\Model\TestResult;
use Tiden\PHPUnitReporter\Core\Run\RunCoordinator;
use Tiden\PHPUnitReporter\Core\Transform\ResultTransformer;

/** Mode "tiden": reports over HTTP as the run proceeds. */
final class RunReporter implements InternalReporter
{
    /** @var list<TestResult> */
    private array $buffer = [];

    private bool $started = false;

    /**
     * Set once the run can no longer be reported to — an unreachable API, a
     * rejected token. Everything downstream becomes a no-op instead of
     * throwing on every single test.
     */
    private bool $givenUp = false;

    public function __construct(
        private readonly Config $config,
        private readonly TidenApi $api,
        private readonly RunCoordinator $coordinator,
        private readonly ResultTransformer $transformer,
        private readonly FilePathResolver $filePaths,
        private readonly Logger $logger,
    ) {}

    public function startRun(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->runSeq();
    }

    public function addResult(TestResult $result): void
    {
        if ($this->givenUp) {
            return;
        }

        $this->buffer[] = $result;

        if (count($this->buffer) >= $this->config->batch->size) {
            $this->flush();
        }
    }

    public function complete(): void
    {
        $this->flush();

        if ($this->givenUp) {
            return;
        }

        try {
            $this->coordinator->finish($this->filePaths->resolvedCount(), $this->filePaths->omittedCount());
        } catch (TidenException $e) {
            // The suite's own verdict is the thing under test. A reporter that
            // throws here turns a green build red for a reason that has nothing
            // to do with the code under test.
            $this->logger->error(sprintf('failed to finish the run: %s', $e->getMessage()));
        }
    }

    /**
     * The run sequence, or null once reporting has been given up on.
     *
     * Creating the run is the one call that cannot be retried per batch: if it
     * fails there is nothing to report into, so we say so once and go quiet
     * rather than raising the same error for every test that follows.
     */
    private function runSeq(): ?int
    {
        if ($this->givenUp) {
            return null;
        }

        try {
            return $this->coordinator->start();
        } catch (TidenException $e) {
            $this->givenUp = true;
            $this->buffer = [];
            $this->logger->error(sprintf(
                'giving up on reporting to Tiden: %s. The test run itself is unaffected.',
                $e->getMessage(),
            ));

            return null;
        }
    }

    private function flush(): void
    {
        if ($this->buffer === [] || $this->givenUp) {
            return;
        }

        $runSeq = $this->runSeq();

        if ($runSeq === null) {
            return;
        }

        // Take the buffer before sending. A batch that fails is not silently
        // retried with different contents on the next flush; the same payload
        // carries the same result ids, so a genuine retry is a duplicate
        // server-side rather than a new row.
        $pending = $this->buffer;
        $this->buffer = [];

        foreach (array_chunk($pending, $this->config->batch->size) as $chunk) {
            $payload = array_map(
                fn (TestResult $result): array => $this->transformer->toResultCreate($result),
                $chunk,
            );

            try {
                $this->api->reportResults($runSeq, $payload);
            } catch (TidenException $e) {
                // Losing a batch must not take the test suite down with it: the
                // suite's own verdict is the thing under test, not ours.
                $this->logger->error(sprintf('failed to report %d result(s): %s', count($chunk), $e->getMessage()));
            }
        }
    }
}
