<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core\Transform;

use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Model\Status;
use Tiden\PHPUnitReporter\Core\Model\SuiteSegment;
use Tiden\PHPUnitReporter\Core\Model\TestResult;
use Tiden\PHPUnitReporter\Core\Transform\ResultTransformer;
use Tiden\PHPUnitReporter\Core\Uuid;

final class ResultTransformerTest extends TestCase
{
    public function test_produces_the_result_create_shape(): void
    {
        $result = $this->makeResult();
        $result->status = Status::Failed;
        $result->endTime = 1_800_000_001.5;
        $result->stacktrace = "#0 foo.php(1)\n";
        $result->message = 'nope';
        $result->params = ['dataset' => 'happy path'];
        $result->tags = ['smoke', 'billing', 'smoke'];
        $result->fields = ['layer' => 'unit'];

        $body = (new ResultTransformer)->toResultCreate($result);

        $this->assertSame([
            'id' => $result->id,
            'title' => 'testBar',
            'signature' => 'php/v3::footest::testbar',
            'execution' => [
                'status' => 'failed',
                'startTime' => 1_800_000_000.0,
                'duration' => '1500',
                'endTime' => 1_800_000_001.5,
                'stacktrace' => "#0 foo.php(1)\n",
                'thread' => 'worker-3',
            ],
            'suitePath' => [['title' => 'Tests'], ['title' => 'FooTest']],
            'defect' => false,
            'fields' => ['layer' => 'unit', 'file_path' => 'tests/FooTest.php', 'tags' => 'smoke,billing'],
            'params' => ['dataset' => 'happy path'],
            'message' => 'nope',
        ], $body);
    }

    /**
     * Three shapes the wire format cares about and a hand-written client can
     * get wrong without anything complaining until results are silently
     * rejected.
     */
    public function test_duration_is_a_millisecond_int64_sent_as_a_string(): void
    {
        $result = $this->makeResult();
        $result->endTime = 1_800_000_000.25;

        $execution = (new ResultTransformer)->toResultCreate($result)['execution'];

        $this->assertSame('250', $execution['duration']);
        $this->assertIsString($execution['duration']);
    }

    public function test_timestamps_are_fractional_epoch_seconds_not_milliseconds(): void
    {
        $execution = (new ResultTransformer)->toResultCreate($this->makeResult())['execution'];

        $this->assertIsFloat($execution['startTime']);
        $this->assertEqualsWithDelta(1_800_000_000.0, $execution['startTime'], 0.001);
    }

    public function test_suite_path_entries_are_objects_root_to_leaf(): void
    {
        $suitePath = (new ResultTransformer)->toResultCreate($this->makeResult())['suitePath'];

        $this->assertSame([['title' => 'Tests'], ['title' => 'FooTest']], $suitePath);
    }

    /**
     * No JS reporter sends externalId either. Identity resolves through the
     * signature branch, and a wrong externalId would be a permanent duplicate
     * because ingest keys on external_id first.
     */
    public function test_external_id_is_never_sent(): void
    {
        $this->assertArrayNotHasKey('externalId', (new ResultTransformer)->toResultCreate($this->makeResult()));
    }

    public function test_empty_collections_are_omitted_rather_than_sent_as_nulls(): void
    {
        $body = (new ResultTransformer)->toResultCreate($this->resultWithoutFilePath());

        $this->assertArrayNotHasKey('params', $body);
        $this->assertArrayNotHasKey('message', $body);
        $this->assertArrayNotHasKey('fields', $body);
    }

    public function test_file_path_travels_inside_fields(): void
    {
        // It is a string-map entry, not a typed field, on ResultCreate.
        $body = (new ResultTransformer)->toResultCreate($this->makeResult());

        $this->assertSame('tests/FooTest.php', $body['fields']['file_path']);
    }

    public function test_status_mapping_is_applied(): void
    {
        $result = $this->makeResult();
        $result->status = Status::Invalid;

        $body = (new ResultTransformer(['invalid' => 'failed']))->toResultCreate($result);

        $this->assertSame('failed', $body['execution']['status']);
    }

    public function test_an_unknown_status_mapping_target_is_ignored_rather_than_sent_through(): void
    {
        $result = $this->makeResult();
        $result->status = Status::Invalid;

        $body = (new ResultTransformer(['invalid' => 'nonsense']))->toResultCreate($result);

        $this->assertSame('invalid', $body['execution']['status']);
    }

    public function test_the_result_id_is_a_uuid_because_the_api_validates_it_as_one(): void
    {
        $this->assertTrue(Uuid::isValid((string) (new ResultTransformer)->toResultCreate($this->makeResult())['id']));
    }

    private function makeResult(): TestResult
    {
        return new TestResult(
            id: Uuid::v4(),
            signature: 'php/v3::footest::testbar',
            title: 'testBar',
            suitePath: [new SuiteSegment('Tests'), new SuiteSegment('FooTest')],
            startTime: 1_800_000_000.0,
            filePath: 'tests/FooTest.php',
            thread: 'worker-3',
        );
    }

    private function resultWithoutFilePath(): TestResult
    {
        return new TestResult(
            id: Uuid::v4(),
            signature: 'php/v3::footest::testbar',
            title: 'testBar',
            suitePath: [],
            startTime: 1_800_000_000.0,
        );
    }
}
