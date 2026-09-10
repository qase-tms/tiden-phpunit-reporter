<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Cross-process coordination, exercised with real processes against a real
 * (stub) HTTP server.
 *
 * ParaTest spawns N workers that each bootstrap the extension independently,
 * and its parent does not run PHPUnit's event system — so nothing but the
 * shared state file decides who creates the run and who closes it. The unit
 * tests cover the decisions; only this covers the locking.
 */
#[Group('integration')]
final class ParaTestCoordinationTest extends TestCase
{
    private const DEAD_PID = 99_999_999;

    private const WORKERS = 3;

    private string $log;

    private string $stateFile;

    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    protected function setUp(): void
    {
        $this->log = sys_get_temp_dir().'/tiden-stub-'.bin2hex(random_bytes(4)).'.log';
        $this->stateFile = sys_get_temp_dir().'/tiden-shared-'.bin2hex(random_bytes(4)).'.json';
        $this->startServer();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server, SIGTERM);
            proc_close($this->server);
        }

        @unlink($this->log);
        @unlink($this->stateFile);
    }

    /**
     * N workers, one run. Without the lock each worker would create its own,
     * and one test run would appear in the UI as N separate runs.
     */
    public function test_concurrent_workers_create_one_run_and_complete_it_once(): void
    {
        $this->runWorkersConcurrently(self::WORKERS);

        $requests = $this->requests();

        $this->assertSame(1, $this->countPaths($requests, '/runs'), 'exactly one run created');
        $this->assertSame(1, $this->countPaths($requests, ':complete'), 'exactly one completion');
        $this->assertGreaterThanOrEqual(self::WORKERS, $this->countPaths($requests, 'results:report'));
    }

    public function test_every_worker_reports_into_the_same_run(): void
    {
        $this->runWorkersConcurrently(self::WORKERS);

        foreach ($this->requests() as $request) {
            if (str_contains($request['path'], 'results:report') || str_contains($request['path'], ':complete')) {
                $this->assertStringContainsString('/runs/4242', $request['path']);
            }
        }
    }

    public function test_no_result_is_reported_twice_across_workers(): void
    {
        $this->runWorkersConcurrently(self::WORKERS);

        $ids = [];

        foreach ($this->requests() as $request) {
            if (! str_contains($request['path'], 'results:report')) {
                continue;
            }

            /** @var array{results: list<array{id: string}>} $body */
            $body = json_decode($request['body'], true);

            foreach ($body['results'] as $result) {
                $ids[] = $result['id'];
            }
        }

        $this->assertNotEmpty($ids);
        $this->assertSame($ids, array_values(array_unique($ids)), 'result ids are idempotency keys and must be unique per attempt');
    }

    /**
     * The safe direction, end to end. A worker that registered and then died
     * leaves the run OPEN: an incomplete run fails a quality gate, whereas a
     * completed run silently missing that worker's results reads as green.
     */
    public function test_a_run_is_left_open_when_a_registered_worker_never_finished(): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('needs ext-posix');
        }

        // Stand in for a worker that registered and was then killed. The state
        // is written directly rather than through StateStore, because the owner
        // must be the parent the spawned workers will see — this process — not
        // this process's own parent.
        file_put_contents($this->stateFile, (string) json_encode([
            'runId' => 4242,
            'owner' => getmypid(),
            'completed' => false,
            'workers' => [(string) self::DEAD_PID => ['startedAt' => time(), 'done' => false]],
        ]));

        $this->runWorkersConcurrently(1);

        $requests = $this->requests();

        $this->assertGreaterThan(0, $this->countPaths($requests, 'results:report'), 'the surviving worker still reported');
        $this->assertSame(0, $this->countPaths($requests, ':complete'), 'but the run was deliberately not completed');
    }

    /**
     * The failure CI caught and three concurrent workers on a fast machine did
     * not: a worker that starts only after another has finished. The first
     * worker used to delete the state file on completion, so the second found
     * none and created a SECOND run — one invocation, two runs, each holding
     * half the suite and each looking complete.
     */
    public function test_a_worker_starting_after_another_finished_does_not_open_a_second_run(): void
    {
        $this->runWorkersConcurrently(1);
        $this->runWorkersConcurrently(1);

        $requests = $this->requests();

        $this->assertSame(1, $this->countPaths($requests, '/runs'), 'still exactly one run');
    }

    /**
     * Spawns workers with proc_open's ARRAY form, so no shell sits between this
     * process and the worker. With the string form /bin/sh -c becomes the
     * worker's parent, which is not how ParaTest spawns workers and changes the
     * very thing this test is about — the parent every worker shares.
     */
    private function runWorkersConcurrently(int $count): void
    {
        $root = dirname(__DIR__, 2);
        $processes = [];
        $pipes = [];

        for ($i = 0; $i < $count; $i++) {
            $env = getenv();
            $env['TIDEN_MODE'] = 'tiden';
            $env['TIDEN_BASE_URL'] = 'http://127.0.0.1:'.$this->port;
            $env['TIDEN_API_TOKEN'] = 'tfy_stub';
            $env['TIDEN_PRODUCT_ID'] = 'p1';
            $env['TIDEN_STATE_FILE'] = $this->stateFile;
            $env['TIDEN_ROOT_DIR'] = $root;
            $env['TEST_TOKEN'] = (string) $i;

            $processes[] = proc_open(
                [PHP_BINARY, $root.'/vendor/bin/phpunit', '-c', $root.'/examples/phpunit.xml', '--no-coverage'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i],
                $root,
                $env,
            );
        }

        foreach ($processes as $index => $process) {
            if (is_resource($process)) {
                stream_get_contents($pipes[$index][1]);
                stream_get_contents($pipes[$index][2]);
                proc_close($process);
            }
        }
    }

    private function startServer(): void
    {
        $root = dirname(__DIR__, 2);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $port = random_int(20000, 60000);

            $env = getenv();
            $env['TIDEN_STUB_LOG'] = $this->log;

            $process = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:'.$port, $root.'/tests/Integration/stub-server.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
                $env,
            );

            if (! is_resource($process)) {
                continue;
            }

            if ($this->waitForServer($port)) {
                $this->server = $process;
                $this->port = $port;

                return;
            }

            proc_terminate($process, SIGTERM);
            proc_close($process);
        }

        $this->markTestSkipped('could not start the stub API server');
    }

    private function waitForServer(int $port): bool
    {
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

            if (is_resource($socket)) {
                fclose($socket);

                return true;
            }

            usleep(100_000);
        }

        return false;
    }

    /** @return list<array{path: string, body: string}> */
    private function requests(): array
    {
        if (! is_file($this->log)) {
            return [];
        }

        $requests = [];

        foreach (file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            /** @var array{path: string, body: string} $decoded */
            $decoded = json_decode($line, true);
            $requests[] = $decoded;
        }

        return $requests;
    }

    /** @param list<array{path: string, body: string}> $requests */
    private function countPaths(array $requests, string $suffix): int
    {
        return count(array_filter(
            $requests,
            static fn (array $r): bool => $suffix === '/runs'
                ? str_ends_with($r['path'], '/runs')
                : str_contains($r['path'], $suffix),
        ));
    }
}
