<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core\Run;

use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Client\HttpResponse;
use Tiden\PHPUnitReporter\Core\Client\TidenApi;
use Tiden\PHPUnitReporter\Core\Config\Config;
use Tiden\PHPUnitReporter\Core\Config\RunConfig;
use Tiden\PHPUnitReporter\Core\Run\RunCoordinator;
use Tiden\PHPUnitReporter\Core\Run\StateStore;
use Tiden\PHPUnitReporter\Tests\Support\FakeTransport;
use Tiden\PHPUnitReporter\Tests\Support\RecordingLogger;

final class RunCoordinatorTest extends TestCase
{
    private const DEAD_PID = 99_999_999;

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/tiden-coord-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        putenv('TIDEN_RUN_ID');
    }

    public function test_creates_a_run_and_completes_it(): void
    {
        $transport = new FakeTransport([new HttpResponse(200, '{"run":{"seqNum":11}}'), new HttpResponse(200, '{}')]);
        $coordinator = $this->coordinator($transport);

        $this->assertSame(11, $coordinator->start());
        $coordinator->finish(3, 0);

        $this->assertSame(1, $transport->countRequestsTo('/runs'));
        $this->assertSame(1, $transport->countRequestsTo(':complete'));
    }

    public function test_adopts_an_externally_created_run_without_calling_create(): void
    {
        $transport = FakeTransport::respondingWith(200, '{}');
        $coordinator = $this->coordinator($transport, new RunConfig(id: 77));

        $this->assertSame(77, $coordinator->start());
        $this->assertSame(0, $transport->countRequestsTo('/runs'), 'a pre-created run is adopted, not duplicated');
    }

    /** The sharded-CI contract: the orchestrator, not the reporter, closes the run. */
    public function test_respects_run_complete_false(): void
    {
        $transport = new FakeTransport([new HttpResponse(200, '{"run":{"seqNum":11}}')]);
        $coordinator = $this->coordinator($transport, new RunConfig(complete: false));

        $coordinator->start();
        $coordinator->finish(3, 0);

        $this->assertSame(0, $transport->countRequestsTo(':complete'));
    }

    /**
     * The safe direction. A killed ParaTest worker means results are missing;
     * completing the run anyway would present a partial run as a finished one,
     * and a partial run of passing tests reads as green.
     */
    public function test_refuses_to_complete_a_run_whose_worker_died(): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('needs ext-posix');
        }

        $transport = new FakeTransport([new HttpResponse(200, '{"run":{"seqNum":11}}')]);
        $logger = new RecordingLogger;
        $coordinator = $this->coordinator($transport, logger: $logger);

        $coordinator->start();
        // A second worker registers and is then killed without finishing.
        (new StateStore($this->path))->startRun(self::DEAD_PID, static fn (): int => 11);

        $decision = $coordinator->finish(3, 0);

        $this->assertTrue($decision->isAbandoned());
        $this->assertSame(0, $transport->countRequestsTo(':complete'));
        $this->assertTrue($logger->has('ERROR', 'exited without reporting'));
        $this->assertStringContainsString('left open on purpose', $logger->joined());
    }

    /**
     * The container misconfiguration: every test file resolved outside the
     * root, so no case in the run can ever be linked to a requirement. Silently
     * completing would look exactly like success.
     */
    public function test_refuses_to_complete_when_not_one_result_carried_a_file_path(): void
    {
        $transport = new FakeTransport([new HttpResponse(200, '{"run":{"seqNum":11}}')]);
        $logger = new RecordingLogger;
        $coordinator = $this->coordinator($transport, logger: $logger);

        $coordinator->start();
        $decision = $coordinator->finish(0, 12);

        $this->assertTrue($decision->shouldComplete(), 'this worker did own completion');
        $this->assertSame(0, $transport->countRequestsTo(':complete'), 'but it declined to complete');
        $this->assertTrue($logger->has('ERROR', 'TIDEN_ROOT_DIR'));
    }

    public function test_an_empty_run_is_still_completed(): void
    {
        // No tests ran, so no paths were seen. That is not a misconfiguration.
        $transport = new FakeTransport([new HttpResponse(200, '{"run":{"seqNum":11}}'), new HttpResponse(200, '{}')]);
        $coordinator = $this->coordinator($transport);

        $coordinator->start();
        $coordinator->finish(0, 0);

        $this->assertSame(1, $transport->countRequestsTo(':complete'));
    }

    public function test_exports_the_run_id_so_nested_processes_inherit_it(): void
    {
        $transport = new FakeTransport([new HttpResponse(200, '{"run":{"seqNum":31}}')]);
        $this->coordinator($transport)->start();

        $this->assertSame('31', getenv('TIDEN_RUN_ID'));
    }

    public function test_the_create_body_carries_the_branch_and_build_metadata(): void
    {
        $transport = new FakeTransport([new HttpResponse(200, '{"run":{"seqNum":11}}')]);
        $config = new RunConfig(title: 'PHP unit', branch: 'feat/x', buildSha: 'abc123');

        $body = $this->coordinator($transport, $config)->createRunBody();

        $this->assertSame('PHP unit', $body['title']);
        $this->assertSame('feat/x', $body['branch']);
        $this->assertSame('abc123', $body['buildSha']);
        $this->assertSame('phpunit', $body['clientMeta']['framework']);
        $this->assertArrayNotHasKey('description', $body, 'unset values are omitted, not sent as empty strings');
    }

    private function coordinator(FakeTransport $transport, ?RunConfig $run = null, ?RecordingLogger $logger = null): RunCoordinator
    {
        $config = new Config(
            environment: 'ci',
            rootDir: '/application',
            productId: 'p1',
            run: $run ?? new RunConfig,
        );

        return new RunCoordinator(
            config: $config,
            api: new TidenApi('https://api.tiden.ai', 'tfy_x', 'p1', $transport),
            state: new StateStore($this->path),
            logger: $logger ?? new RecordingLogger,
            pid: getmypid() ?: 1,
        );
    }
}
