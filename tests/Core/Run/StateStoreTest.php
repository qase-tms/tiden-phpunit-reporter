<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core\Run;

use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Run\StateStore;

final class StateStoreTest extends TestCase
{
    /** A pid far above any platform's pid_max, so it cannot be alive or reused. */
    private const DEAD_PID = 99_999_999;

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/tiden-state-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * The exactly-once property. ParaTest spawns N workers that each bootstrap
     * the extension; N CreateTestRun calls would mean N runs in the UI for one
     * test run.
     */
    public function test_the_run_is_created_only_once_across_workers(): void
    {
        $creations = 0;
        $create = function () use (&$creations): int {
            $creations++;

            return 4242;
        };

        $seqs = [];

        foreach ([101, 102, 103] as $pid) {
            $seqs[] = $this->store()->startRun($pid, $create);
        }

        $this->assertSame(1, $creations);
        $this->assertSame([4242, 4242, 4242], $seqs);
    }

    /**
     * The normal ParaTest ending: every worker registered and every worker
     * finished, so the last one completes the run.
     */
    public function test_completion_happens_once_every_registered_worker_has_finished(): void
    {
        $store = $this->store();
        $pids = [301, 302, 303];

        foreach ($pids as $pid) {
            $store->startRun($pid, static fn (): int => 1);
        }

        $this->assertFalse($store->finish(301, 5, 0)->shouldComplete());
        $this->assertFalse($store->finish(302, 5, 0)->shouldComplete());

        $last = $store->finish(303, 5, 0);

        $this->assertTrue($last->shouldComplete());
        $this->assertFalse($last->isAbandoned());
    }

    /**
     * The whole point of the liveness gate. A worker that is killed never marks
     * itself done, so the surviving worker must NOT complete the run: an
     * incomplete run fails a quality gate, while a completed run missing that
     * worker's results reads as a pass.
     */
    public function test_a_run_whose_worker_died_is_deliberately_left_incomplete(): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('liveness detection needs ext-posix');
        }

        $store = $this->store();
        $store->startRun(getmypid() ?: 1, static fn (): int => 1);
        $store->startRun(self::DEAD_PID, static fn (): int => 1);

        $decision = $store->finish(getmypid() ?: 1, 3, 0);

        $this->assertTrue($decision->isAbandoned());
        $this->assertFalse($decision->shouldComplete());
        $this->assertSame([self::DEAD_PID], $decision->deadWorkers);
    }

    /**
     * Without ext-posix a dead pid is indistinguishable from a live one, so the
     * store answers "alive" and the run waits forever. That is the same safe
     * direction — an unfinished run is never completed on a guess — minus the
     * diagnostic naming the dead worker.
     */
    public function test_an_unfinished_worker_never_yields_completion(): void
    {
        $store = $this->store();
        $store->startRun(401, static fn (): int => 1);
        $store->startRun(402, static fn (): int => 1);

        $this->assertFalse($store->finish(401, 1, 0)->shouldComplete());
    }

    /**
     * Under ParaTest each worker only sees its own tests, so "no result
     * anywhere carried a file_path" is only answerable across all of them.
     */
    public function test_file_path_counters_accumulate_across_workers(): void
    {
        $store = $this->store();

        foreach ([501, 502] as $pid) {
            $store->startRun($pid, static fn (): int => 1);
        }

        $store->finish(501, 0, 4);
        $decision = $store->finish(502, 7, 1);

        $this->assertSame(7, $decision->resolvedPaths);
        $this->assertSame(5, $decision->omittedPaths);
        $this->assertFalse($decision->everyPathUnresolved(), 'one worker did resolve paths');
    }

    public function test_every_path_unresolved_is_only_true_when_paths_were_seen_and_none_resolved(): void
    {
        $store = $this->store();
        $store->startRun(601, static fn (): int => 1);

        $decision = $store->finish(601, 0, 9);

        $this->assertTrue($decision->everyPathUnresolved());
    }

    public function test_an_empty_run_is_not_mistaken_for_a_misconfiguration(): void
    {
        $store = $this->store();
        $store->startRun(701, static fn (): int => 1);

        // No tests ran, so no paths were seen. That is not the same as every
        // path failing to resolve, and must not block completion.
        $decision = $store->finish(701, 0, 0);

        $this->assertTrue($decision->shouldComplete());
        $this->assertFalse($decision->everyPathUnresolved());
    }

    public function test_a_corrupt_state_file_does_not_take_the_run_down(): void
    {
        file_put_contents($this->path, 'not json at all');

        $seq = $this->store()->startRun(801, static fn (): int => 77);

        $this->assertSame(77, $seq);
    }

    public function test_default_path_is_scoped_to_the_project_and_the_para_test_parent(): void
    {
        $first = StateStore::defaultPath(null, '/project/one');
        $second = StateStore::defaultPath(null, '/project/two');

        $this->assertNotSame($first, $second, 'two projects must not share one run');
        $this->assertStringStartsWith(sys_get_temp_dir(), $first, 'never inside vendor/');
        $this->assertSame('/explicit.json', StateStore::defaultPath('/explicit.json', '/project/one'));
    }

    private function store(): StateStore
    {
        return new StateStore($this->path);
    }
}
