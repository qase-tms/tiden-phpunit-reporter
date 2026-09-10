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

    private function transport(): FakeTransport
    {
        return new FakeTransport([new HttpResponse(200, '{"run":{"seqNum":9},"accepted":"1","duplicates":"0"}')]);
    }

    private function reporter(FakeTransport $transport, int $batchSize = 200, ?RecordingLogger $logger = null): RunReporter
    {
        $config = new Config(productId: 'p1', batch: new BatchConfig($batchSize));
        $logger ??= new RecordingLogger;
        $api = new TidenApi('https://api.tiden.ai', 'tfy_x', 'p1', $transport, $logger);

        return new RunReporter(
            $config,
            $api,
            new RunCoordinator($config, $api, new StateStore($this->stateFile), $logger, getmypid() ?: 1),
            new ResultTransformer,
            new FilePathResolver(dirname(__DIR__, 3)),
            $logger,
        );
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
