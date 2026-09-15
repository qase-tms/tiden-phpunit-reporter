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

    /**
     * What this worker actually got onto the wire, and what it lost trying.
     *
     * Kept per worker and summed across all of them in the state file, because
     * under ParaTest no single process sees the whole run — and a run that is
     * short by a few hundred results is otherwise indistinguishable from a run
     * that was always that size.
     */
    private int $reported = 0;

    private int $failed = 0;

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
            $this->coordinator->finish(
                $this->filePaths->resolvedCount(),
                $this->filePaths->omittedCount(),
                $this->reported,
                $this->failed,
            );
        } catch (\Throwable $e) {
            // The suite's own verdict is the thing under test. A reporter that
            // throws here turns a green build red for a reason that has nothing
            // to do with the code under test. Throwable, not TidenException: an
            // unexpected type escaping here is absorbed by the test runner's
            // event dispatcher as a warning nobody reads.
            $this->logger->error(sprintf('failed to finish the run: %s: %s', $e::class, $e->getMessage()));
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
            $this->send($runSeq, $chunk);
        }
    }

    /**
     * Report one chunk, and account for it either way.
     *
     * Throwable rather than TidenException: json_encode throws \JsonException,
     * which is not one, and an exception escaping this far is swallowed by the
     * test runner's event dispatcher as a warning that never reaches a CI log.
     * That turns a lost batch into a loss with no signal at all.
     *
     * @param  list<TestResult>  $chunk
     */
    private function send(int $runSeq, array $chunk): void
    {
        $count = count($chunk);

        try {
            $payload = array_map(
                fn (TestResult $result): array => $this->transformer->toResultCreate($result),
                $chunk,
            );

            $outcome = $this->api->reportResults($runSeq, $payload);
        } catch (\Throwable $e) {
            // Losing a batch must not take the test suite down with it: the
            // suite's own verdict is the thing under test, not ours.
            $this->failed += $count;
            $this->logger->error(sprintf(
                'failed to report %d result(s): %s: %s',
                $count,
                $e::class,
                $e->getMessage(),
            ));

            return;
        }

        $landed = $outcome['accepted'] + $outcome['duplicates'];
        $this->reported += min($landed, $count);

        // A 2xx that took fewer rows than it was given is the quietest way to
        // lose results there is: nothing throws and nothing is logged.
        if ($landed < $count) {
            $this->failed += $count - $landed;
            $this->logger->warning(sprintf(
                'reported %d result(s) but the API accepted %d and deduplicated %d; %d were not recorded',
                $count,
                $outcome['accepted'],
                $outcome['duplicates'],
                $count - $landed,
            ));
        }
    }
}
