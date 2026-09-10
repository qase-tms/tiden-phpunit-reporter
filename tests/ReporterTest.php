<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests;

use PHPUnit\Event\Code\TestDox;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\TestData\DataFromDataProvider;
use PHPUnit\Event\TestData\TestDataCollection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Metadata\MetadataCollection;
use Tiden\PHPUnitReporter\Attribute\AttributeReader;
use Tiden\PHPUnitReporter\Core\Identity\FilePathResolver;
use Tiden\PHPUnitReporter\Core\Model\Status;
use Tiden\PHPUnitReporter\Reporter;
use Tiden\PHPUnitReporter\Tests\Support\RecordingInternalReporter;
use Tiden\PHPUnitReporter\Tests\Support\RecordingLogger;
use Tiden\PHPUnitReporter\Tiden;

final class ReporterTest extends TestCase
{
    private RecordingInternalReporter $internal;

    private Reporter $reporter;

    protected function setUp(): void
    {
        $this->internal = new RecordingInternalReporter;
        $this->reporter = Reporter::create(
            $this->internal,
            new AttributeReader,
            new FilePathResolver(dirname(__DIR__)),
            new RecordingLogger,
            'PHPUnit tests',
        );
    }

    protected function tearDown(): void
    {
        Reporter::reset();
    }

    public function test_a_passing_test_becomes_one_result(): void
    {
        $test = $this->test('Tests\Unit\FooTest', 'testBar');

        $this->reporter->startTest($test);
        $this->reporter->updateStatus($test, Status::Passed);
        $this->reporter->completeTest($test);

        $this->assertCount(1, $this->internal->results);
        $this->assertSame(Status::Passed, $this->internal->results[0]->status);
        $this->assertSame('php/v3::tests::unit::footest::testbar', $this->internal->results[0]->signature);
    }

    public function test_a_result_is_flushed_once_even_if_complete_is_called_twice(): void
    {
        $test = $this->test('Tests\Unit\FooTest', 'testBar');

        $this->reporter->startTest($test);
        $this->reporter->completeTest($test);
        $this->reporter->completeTest($test);

        $this->assertCount(1, $this->internal->results);
    }

    /**
     * A preparation error produces no Prepared and no Finished event. Without
     * creating the result on demand the test would vanish from the run — and a
     * run that silently shrank looks exactly like a run where everything passed.
     */
    public function test_an_outcome_for_a_test_that_never_started_is_still_recorded(): void
    {
        $test = $this->test('Tests\Unit\BrokenTest', 'testSetupExplodes');

        $this->reporter->updateStatus($test, Status::Invalid, 'setUp failed');
        $this->reporter->completeTest($test);

        $this->assertCount(1, $this->internal->results);
        $this->assertSame(Status::Invalid, $this->internal->results[0]->status);
    }

    /** Anything that never saw a Finished event is swept at the end of the run. */
    public function test_unfinished_results_are_swept_when_the_run_ends(): void
    {
        $this->reporter->startTest($this->test('Tests\Unit\FooTest', 'testKilledMidway'));
        $this->reporter->completeTestRun();

        $this->assertCount(1, $this->internal->results);
        $this->assertSame(1, $this->internal->completions);
    }

    /**
     * Two data rows, ONE case: the identity is param-free, and the rows are
     * told apart by their params.
     */
    public function test_data_provider_rows_share_a_signature_and_differ_in_params(): void
    {
        foreach (['first row', 'second row'] as $dataSetName) {
            $test = $this->test('Tests\Unit\FooTest', 'testRows', $dataSetName);
            $this->reporter->startTest($test);
            $this->reporter->completeTest($test);
        }

        $this->assertCount(2, $this->internal->results);
        $this->assertSame($this->internal->results[0]->signature, $this->internal->results[1]->signature);
        $this->assertSame(['dataset' => 'first row', 'data' => '1, 2'], $this->internal->results[0]->params);
        $this->assertSame(['dataset' => 'second row', 'data' => '1, 2'], $this->internal->results[1]->params);
        $this->assertNotSame($this->internal->results[0]->id, $this->internal->results[1]->id);
    }

    public function test_the_file_path_is_repo_relative(): void
    {
        $test = $this->test('Tests\Unit\FooTest', 'testBar', file: __FILE__);

        $this->reporter->startTest($test);
        $this->reporter->completeTest($test);

        $this->assertSame('tests/ReporterTest.php', $this->internal->results[0]->filePath);
    }

    /**
     * A test outside the configured root is reported without a file_path rather
     * than with a fabricated one: it is unlinkable, but it is not linked to the
     * wrong requirement.
     */
    public function test_a_file_outside_the_root_is_reported_without_a_path_rather_than_dropped(): void
    {
        $test = $this->test('Tests\Unit\FooTest', 'testBar', file: '/somewhere/else/FooTest.php');

        $this->reporter->startTest($test);
        $this->reporter->completeTest($test);

        $this->assertCount(1, $this->internal->results);
        $this->assertNull($this->internal->results[0]->filePath);
    }

    public function test_the_root_suite_is_prepended_to_the_suite_path(): void
    {
        $test = $this->test('Tests\Unit\FooTest', 'testBar');

        $this->reporter->startTest($test);
        $this->reporter->completeTest($test);

        $titles = array_map(
            static fn ($segment): string => $segment->title,
            $this->internal->results[0]->suitePath,
        );

        $this->assertSame(['PHPUnit tests', 'Tests', 'Unit', 'FooTest'], $titles);
    }

    public function test_the_static_helpers_affect_the_running_test(): void
    {
        $test = $this->test('Tests\Unit\FooTest', 'testBar');

        $this->reporter->startTest($test);
        Tiden::title('A better name');
        Tiden::comment('note one');
        Tiden::comment('note two');
        $this->reporter->completeTest($test);

        $this->assertSame('A better name', $this->internal->results[0]->title);
        $this->assertSame("note one\nnote two", $this->internal->results[0]->message);
    }

    public function test_the_static_helpers_are_safe_outside_a_test(): void
    {
        Reporter::reset();

        // No exception, no output: the package is installed but not bootstrapped.
        Tiden::title('ignored');
        Tiden::comment('ignored');

        $this->assertNull(Reporter::instance());
    }

    public function test_the_run_is_started_only_once(): void
    {
        $this->reporter->startTestRun();
        $this->reporter->startTestRun();

        $this->assertSame(1, $this->internal->runsStarted);
    }

    public function test_the_thread_is_taken_from_the_para_test_worker_token(): void
    {
        putenv('TEST_TOKEN=4');

        try {
            $test = $this->test('Tests\Unit\FooTest', 'testBar');
            $this->reporter->startTest($test);
            $this->reporter->completeTest($test);

            $this->assertSame('worker-4', $this->internal->results[0]->thread);
        } finally {
            putenv('TEST_TOKEN');
        }
    }

    private function test(string $className, string $methodName, ?string $dataSetName = null, ?string $file = null): TestMethod
    {
        $testData = $dataSetName === null
            ? TestDataCollection::fromArray([])
            : TestDataCollection::fromArray([DataFromDataProvider::from($dataSetName, '1, 2', '1, 2')]);

        return new TestMethod(
            $className,
            $methodName,
            $file ?? __FILE__,
            10,
            new TestDox($className, $methodName, $methodName),
            MetadataCollection::fromArray([]),
            $testData,
        );
    }
}
