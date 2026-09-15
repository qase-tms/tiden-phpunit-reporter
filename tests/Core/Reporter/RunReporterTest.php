<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core\Reporter;

use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Client\HttpResponse;
use Tiden\PHPUnitReporter\Core\Client\TidenApi;
use Tiden\PHPUnitReporter\Core\Config\BatchConfig;
use Tiden\PHPUnitReporter\Core\Config\Config;
use Tiden\PHPUnitReporter\Core\Identity\FilePathResolver;
use Tiden\PHPUnitReporter\Core\Model\SuiteSegment;
use Tiden\PHPUnitReporter\Core\Model\TestResult;
use Tiden\PHPUnitReporter\Core\Reporter\RunReporter;
use Tiden\PHPUnitReporter\Core\Run\RunCoordinator;
use Tiden\PHPUnitReporter\Core\Run\StateStore;
use Tiden\PHPUnitReporter\Core\Transform\ResultTransformer;
use Tiden\PHPUnitReporter\Core\Uuid;
use Tiden\PHPUnitReporter\Tests\Support\FakeTransport;
use Tiden\PHPUnitReporter\Tests\Support\RecordingLogger;

final class RunReporterTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir().'/tiden-rr-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
        putenv('TIDEN_RUN_ID');
    }

    public function test_results_are_flushed_when_the_batch_fills_up(): void
    {
        $transport = $this->transport();
        $reporter = $this->reporter($transport, batchSize: 2);

        $reporter->startRun();

        foreach (range(1, 5) as $ignored) {
            $reporter->addResult($this->makeResult());
        }

        $this->assertSame(2, $transport->countRequestsTo('results:report'), 'two full batches sent mid-run');

        $reporter->complete();

        $this->assertSame(3, $transport->countRequestsTo('results:report'), 'the tail is flushed at the end');
    }

    public function test_the_batch_never_exceeds_the_api_limit(): void
    {
        $transport = $this->transport();
        // The API accepts 1..2000 results per call; a larger configured size is
        // clamped rather than sent and rejected.
        $reporter = $this->reporter($transport, batchSize: 99_999);

        $reporter->startRun();

        foreach (range(1, 2_001) as $ignored) {
            $reporter->addResult($this->makeResult());
        }

        $reporter->complete();

        foreach ($transport->requests as $request) {
            if (! str_contains($request['url'], 'results:report')) {
                continue;
            }

            /** @var array{results: list<mixed>} $body */
            $body = json_decode($request['json'], true);
            $this->assertLessThanOrEqual(BatchConfig::MAX_SIZE, count($body['results']));
        }
    }

    public function test_nothing_is_sent_when_no_test_ran(): void
    {
        $transport = $this->transport();
        $reporter = $this->reporter($transport);

        $reporter->startRun();
        $reporter->complete();

        $this->assertSame(0, $transport->countRequestsTo('results:report'));
    }

    /**
     * A failed upload must not take the suite down with it. The suite's own
     * verdict is the thing under test; a reporter that throws would turn a
     * green build red for a reason that has nothing to do with the code.
     */
    public function test_a_failed_batch_is_logged_rather_than_thrown(): void
    {
        $transport = new FakeTransport([
            new HttpResponse(200, '{"run":{"seqNum":9}}'),
            new HttpResponse(500, '{"message":"boom"}'),
        ]);
        $logger = new RecordingLogger;
        $reporter = $this->reporter($transport, logger: $logger);

        $reporter->startRun();
        $reporter->addResult($this->makeResult());
        $reporter->complete();

        $this->assertTrue($logger->has('ERROR', 'failed to report 1 result'));
    }

    /**
     * The buffer is taken before sending, so a retried batch carries the same
     * result ids and is a server-side duplicate rather than a new row. This is
     * the property the replaced bridge lost when it persisted its idempotency
     * keys only after every batch had succeeded.
     */
    public function test_a_failed_batch_is_not_resent_with_the_next_one(): void
    {
        $transport = new FakeTransport([
            new HttpResponse(200, '{"run":{"seqNum":9}}'),
            new HttpResponse(500, '{}'),
            new HttpResponse(200, '{"accepted":"1"}'),
        ]);
        $reporter = $this->reporter($transport, batchSize: 1);

        $reporter->startRun();
        $reporter->addResult($this->makeResult());
        $reporter->addResult($this->makeResult());
        $reporter->complete();

        $sent = [];

        foreach ($transport->requests as $request) {
            if (! str_contains($request['url'], 'results:report')) {
                continue;
            }

            /** @var array{results: list<array{id: string}>} $body */
            $body = json_decode($request['json'], true);

            foreach ($body['results'] as $result) {
                $sent[] = $result['id'];
            }
        }

        $this->assertSame($sent, array_values(array_unique($sent)), 'a lost batch is not replayed into the next one');
    }

    /**
     * If the run cannot be created there is nothing to report into. The suite
     * must still finish normally: the reporter says so once and goes quiet,
     * rather than raising the same error for every remaining test.
     */
    public function test_an_unreachable_api_silences_the_reporter_instead_of_failing_the_suite(): void
    {
        $transport = new FakeTransport([new HttpResponse(503, '{}')]);
        $logger = new RecordingLogger;
        $reporter = $this->reporter($transport, logger: $logger);

        $reporter->startRun();

        foreach (range(1, 10) as $ignored) {
            $reporter->addResult($this->makeResult());
        }

        $reporter->complete();

        $this->assertTrue($logger->has('ERROR', 'giving up on reporting to Tiden'));
        $this->assertSame(
            1,
            substr_count($logger->joined(), 'giving up on reporting'),
            'the failure is announced once, not once per test',
        );
        $this->assertSame(0, $transport->countRequestsTo('results:report'));
        $this->assertSame(0, $transport->countRequestsTo(':complete'));
    }

    /**
     * The loss this package shipped with: anything that was not a TidenException
     * escaped flush(), and PHPUnit's dispatcher absorbs a throwing subscriber as
     * a warning that no CI log surfaces. The batch vanished with no signal at
     * all — not even the "failed to report" line a TidenException produced.
     */
    public function test_an_unexpected_throwable_is_logged_rather_than_escaping(): void
    {
        $transport = new FakeTransport([
            new HttpResponse(200, '{"run":{"seqNum":9}}'),
            new \LogicException('encoder blew up'),
        ]);
        $logger = new RecordingLogger;
        $reporter = $this->reporter($transport, logger: $logger);

        $reporter->startRun();
        $reporter->addResult($this->makeResult());
        $reporter->complete();

        $this->assertTrue($logger->has('ERROR', 'failed to report 1 result'));
        $this->assertTrue($logger->has('ERROR', 'LogicException'), 'the exception type is named');
    }

    /** One bad batch must not silence the reporter for the rest of the run. */
    public function test_a_failed_batch_does_not_stop_later_batches_from_reporting(): void
    {
        $transport = new FakeTransport([
            new HttpResponse(200, '{"run":{"seqNum":9}}'),
            new \LogicException('boom'),
            new HttpResponse(200, '{"accepted":"1","duplicates":"0"}'),
        ]);
        $reporter = $this->reporter($transport, batchSize: 1);

        $reporter->startRun();
        $reporter->addResult($this->makeResult());
        $reporter->addResult($this->makeResult());
        $reporter->complete();

        $this->assertSame(2, $transport->countRequestsTo('results:report'));
    }

    /**
     * A 2xx that took fewer rows than it was given is the quietest loss there
     * is: nothing throws, and the count was previously discarded unread.
     */
    public function test_a_batch_the_api_did_not_fully_accept_is_warned_about(): void
    {
        $transport = new FakeTransport([
            new HttpResponse(200, '{"run":{"seqNum":9}}'),
            new HttpResponse(200, '{"accepted":"1","duplicates":"0"}'),
        ]);
        $logger = new RecordingLogger;
        $reporter = $this->reporter($transport, batchSize: 2, logger: $logger);

        $reporter->startRun();
        $reporter->addResult($this->makeResult());
        $reporter->addResult($this->makeResult());
        $reporter->complete();

        $this->assertTrue($logger->has('WARN', '1 were not recorded'));
    }

    /** Deduplicated rows landed; they are not a shortfall. */
    public function test_duplicates_count_as_landed_and_are_not_warned_about(): void
    {
        $transport = new FakeTransport([
            new HttpResponse(200, '{"run":{"seqNum":9}}'),
            new HttpResponse(200, '{"accepted":"1","duplicates":"1"}'),
        ]);
        $logger = new RecordingLogger;
        $reporter = $this->reporter($transport, batchSize: 2, logger: $logger);

        $reporter->startRun();
        $reporter->addResult($this->makeResult());
        $reporter->addResult($this->makeResult());
        $reporter->complete();

        $this->assertFalse($logger->has('WARN', 'not recorded'));
    }

    /**
     * The reconciliation a caller needs: what the run got onto the wire and what
     * it lost, said once, by whichever worker finished last.
     */
    public function test_the_run_summary_reconciles_what_was_reported_against_what_was_lost(): void
    {
        $transport = new FakeTransport([
            new HttpResponse(200, '{"run":{"seqNum":9}}'),
            new HttpResponse(500, '{}'),
            new HttpResponse(200, '{"accepted":"1","duplicates":"0"}'),
        ]);
        $logger = new RecordingLogger;
        $reporter = $this->reporter($transport, batchSize: 1, logger: $logger);

        $reporter->startRun();
        $reporter->addResult($this->makeResult());
        $reporter->addResult($this->makeResult());
        $reporter->complete();

        $this->assertTrue(
            $logger->has('WARN', 'reported 1 result(s) to Tiden; 1 failed to report'),
            'the summary names both totals: '.$logger->joined(),
        );
    }

    /** A clean run still says what it reported, so the count can be checked. */
    public function test_a_clean_run_reports_its_total_at_info_level(): void
    {
        $transport = $this->transport();
        $logger = new RecordingLogger;
        $reporter = $this->reporter($transport, batchSize: 1, logger: $logger);

        $reporter->startRun();
        $reporter->addResult($this->makeResult());
        $reporter->complete();

        $this->assertTrue($logger->has('INFO', 'reported 1 result(s) to Tiden; 0 failed to report'));
    }

    /**
     * A single malformed byte — from a truncated message or stacktrace — used to
     * make json_encode refuse the entire body, taking every innocent result in
     * the batch with it.
     */
    public function test_a_result_carrying_invalid_utf8_is_still_reported(): void
    {
        $transport = $this->transport();
        $logger = new RecordingLogger;
        $reporter = $this->reporter($transport, logger: $logger);

        $result = $this->makeResult();
        $result->appendMessage("broken \xC3 tail");

        $reporter->startRun();
        $reporter->addResult($result);
        $reporter->addResult($this->makeResult());
        $reporter->complete();

        $this->assertSame(1, $transport->countRequestsTo('results:report'));
        $this->assertFalse($logger->has('ERROR', 'failed to report'));

        $this->assertCount(2, $this->reportedResults($transport), 'the innocent result travelled with it');
    }

    private function transport(): FakeTransport
    {
        return new FakeTransport([new HttpResponse(200, '{"run":{"seqNum":9},"accepted":"1","duplicates":"0"}')]);
    }

    private function reporter(FakeTransport $transport, int $batchSize = 200, ?RecordingLogger $logger = null): RunReporter
    {
        $config = new Config(productId: 'p1', batch: new BatchConfig($batchSize));
        $logger ??= new RecordingLogger;
        // A no-op sleeper: 503 is retryable, and without this the unreachable-API
        // case would spend half a minute in real backoffs.
        $api = new TidenApi(
            'https://api.tiden.ai',
            'tfy_x',
            'p1',
            $transport,
            $logger,
            static function (int $milliseconds): void {},
        );

        return new RunReporter(
            $config,
            $api,
            new RunCoordinator($config, $api, new StateStore($this->stateFile), $logger, getmypid() ?: 1),
            new ResultTransformer,
            new FilePathResolver(dirname(__DIR__, 3)),
            $logger,
        );
    }

    /**
     * Every result id that reached the wire, across all results:report calls.
     *
     * @return list<string>
     */
    private function reportedResults(FakeTransport $transport): array
    {
        $ids = [];

        foreach ($transport->requests as $request) {
            if (! str_contains($request['url'], 'results:report')) {
                continue;
            }

            /** @var array{results: list<array{id: string}>} $body */
            $body = json_decode($request['json'], true);

            foreach ($body['results'] as $result) {
                $ids[] = $result['id'];
            }
        }

        return $ids;
    }

    private function makeResult(): TestResult
    {
        return new TestResult(
            id: Uuid::v4(),
            signature: 'php/v3::footest::testbar',
            title: 'testBar',
            suitePath: [new SuiteSegment('Tests')],
            startTime: microtime(true),
            filePath: 'tests/FooTest.php',
        );
    }
}
