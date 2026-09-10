<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Contract;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tiden\PHPUnitReporter\Core\Client\TidenApi;
use Tiden\PHPUnitReporter\Core\Model\Status;
use Tiden\PHPUnitReporter\Core\Model\SuiteSegment;
use Tiden\PHPUnitReporter\Core\Model\TestResult;
use Tiden\PHPUnitReporter\Core\Transform\ResultTransformer;
use Tiden\PHPUnitReporter\Core\Uuid;
use Tiden\PHPUnitReporter\Tests\Support\FakeTransport;

/**
 * Checks the hand-written client against tiden-specs' openapi.yaml.
 *
 * This package deliberately does not depend on a generated SDK: there is no
 * published PHP tiden/api-client (specs' sdk/php.yml generates into a
 * gitignored directory and the client-sync workflow has rows for Go and
 * TypeScript only). This test is the guard taken in exchange — without it, a
 * misspelled field would only surface as results being silently rejected.
 *
 * The spec lives in a sibling repository, so the test skips when it is not
 * checked out. It runs in the tiden workspace, where it matters, and is a
 * no-op for anyone who installs the package from Packagist.
 *
 * Note the spec is authoritative over commons' hand-written DTOs, which are
 * themselves drifting from it (their CreateTestRunBody is missing
 * intentSessionId and intentBranch).
 */
#[Group('contract')]
final class OpenApiContractTest extends TestCase
{
    private const SPEC_ENV = 'TIDEN_OPENAPI_SPEC';

    private const DEFAULT_SPEC = __DIR__.'/../../../specs/public-api/v1/openapi.yaml';

    /** @var array<string, mixed> */
    private array $spec;

    protected function setUp(): void
    {
        $path = getenv(self::SPEC_ENV);
        $path = is_string($path) && $path !== '' ? $path : self::DEFAULT_SPEC;

        if (! is_file($path)) {
            $this->markTestSkipped(sprintf(
                'tiden-specs is not checked out at "%s"; set %s to run the contract test.',
                $path,
                self::SPEC_ENV,
            ));
        }

        /** @var array<string, mixed> $spec */
        $spec = Yaml::parseFile($path);
        $this->spec = $spec;
    }

    public function test_the_three_operations_exist_at_the_paths_the_client_posts(): void
    {
        foreach ([
            '/v1/products/{productId}/runs',
            '/v1/products/{productId}/runs/{runSeq}/results:report',
            '/v1/products/{productId}/runs/{runSeq}:complete',
        ] as $path) {
            $this->assertArrayHasKey($path, $this->paths(), $path);
            $this->assertArrayHasKey('post', $this->paths()[$path], $path.' is a POST');
        }
    }

    public function test_every_field_the_transformer_emits_exists_on_result_create(): void
    {
        $properties = $this->schema('ResultCreate')['properties'];
        $body = (new ResultTransformer)->toResultCreate($this->makeResult());

        foreach (array_keys($body) as $field) {
            $this->assertArrayHasKey($field, $properties, sprintf('ResultCreate has no "%s"', $field));
        }
    }

    public function test_every_field_the_execution_carries_exists_on_result_execution(): void
    {
        $properties = $this->schema('ResultExecution')['properties'];
        /** @var array<string, mixed> $execution */
        $execution = (new ResultTransformer)->toResultCreate($this->makeResult())['execution'];

        foreach (array_keys($execution) as $field) {
            $this->assertArrayHasKey($field, $properties, sprintf('ResultExecution has no "%s"', $field));
        }
    }

    /**
     * The three shapes most easily got wrong: duration is an int64 sent as a
     * string, the timestamps are plain numbers, and suitePath is a list of
     * objects rather than of strings.
     */
    public function test_the_wire_types_match_the_spec(): void
    {
        $properties = $this->schema('ResultExecution')['properties'];

        $this->assertSame('string', $properties['duration']['type']);
        $this->assertSame('int64', $properties['duration']['format'] ?? null);
        $this->assertSame('number', $properties['startTime']['type']);
        $this->assertSame('number', $properties['endTime']['type']);

        $suitePath = $this->schema('ResultCreate')['properties']['suitePath'];
        $this->assertSame('array', $suitePath['type']);
        $this->assertArrayHasKey('title', $this->schema('SuiteSegment')['properties']);
    }

    /**
     * The spec is generated from protos, so the allowed statuses are not an
     * OpenAPI enum — they are the pipe-separated title on the field
     * ("passed | failed | blocked | skipped | invalid"). Reading that is still
     * reading the contract; asserting our own list against itself would not be.
     */
    public function test_the_status_vocabulary_matches_the_spec_exactly(): void
    {
        $title = $this->schema('ResultExecution')['properties']['status']['title'] ?? null;

        $this->assertIsString($title, 'the spec no longer documents the allowed statuses in the field title');

        $specStatuses = array_values(array_filter(array_map('trim', explode('|', $title))));
        $ours = array_map(static fn (Status $s): string => $s->value, Status::cases());

        sort($ours);
        sort($specStatuses);

        $this->assertSame($specStatuses, $ours);
    }

    public function test_every_field_the_create_body_emits_exists_on_create_test_run_body(): void
    {
        $properties = $this->schema('CreateTestRunBody')['properties'];
        $transport = FakeTransport::respondingWith(200, '{"run":{"seqNum":1}}');

        // Build the body the coordinator would send, without a coordinator.
        $body = [
            'title' => 't', 'description' => 'd', 'environment' => 'ci',
            'branch' => 'main', 'buildSha' => 'abc', 'startedAt' => '2026-01-01T00:00:00Z',
            'clientMeta' => ['framework' => 'phpunit'],
        ];

        foreach (array_keys($body) as $field) {
            $this->assertArrayHasKey($field, $properties, sprintf('CreateTestRunBody has no "%s"', $field));
        }

        // And that the client reads back the field it actually reads.
        (new TidenApi('https://x', 't', 'p', $transport))->createRun($body);
        $this->assertArrayHasKey('seqNum', $this->schema('TestRun')['properties']);
    }

    public function test_file_path_is_a_fields_entry_on_results_not_a_top_level_property(): void
    {
        // fields is a free-form string map on ResultCreate; the typed filePath
        // lives on the ingest/test schemas, which this reporter does not touch.
        $properties = $this->schema('ResultCreate')['properties'];

        $this->assertArrayHasKey('fields', $properties);
        $this->assertArrayNotHasKey('filePath', $properties);
        $this->assertArrayNotHasKey('file_path', $properties);
    }

    /** @return array<string, mixed> */
    private function paths(): array
    {
        /** @var array<string, mixed> */
        return $this->spec['paths'];
    }

    /** @return array<string, mixed> */
    private function schema(string $name): array
    {
        /** @var array<string, array<string, mixed>> $schemas */
        $schemas = $this->spec['components']['schemas'];

        $this->assertArrayHasKey($name, $schemas, sprintf('the spec has no %s schema', $name));

        return $schemas[$name];
    }

    private function makeResult(): TestResult
    {
        $result = new TestResult(
            id: Uuid::v4(),
            signature: 'php/v3::footest::testbar',
            title: 'testBar',
            suitePath: [new SuiteSegment('Tests')],
            startTime: 1_800_000_000.0,
            filePath: 'tests/FooTest.php',
            thread: 'worker-1',
        );

        $result->endTime = 1_800_000_001.0;
        $result->stacktrace = 'trace';
        $result->message = 'message';
        $result->params = ['dataset' => '0'];
        $result->tags = ['smoke'];

        return $result;
    }
}
