<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core\Reporter;

use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Client\HttpResponse;
use Tiden\PHPUnitReporter\Core\Config\ConfigResolver;
use Tiden\PHPUnitReporter\Core\Identity\FilePathResolver;
use Tiden\PHPUnitReporter\Core\Identity\IdentityParts;
use Tiden\PHPUnitReporter\Core\Model\SuiteSegment;
use Tiden\PHPUnitReporter\Core\Model\TestResult;
use Tiden\PHPUnitReporter\Core\Reporter\FileReporter;
use Tiden\PHPUnitReporter\Core\Reporter\NullReporter;
use Tiden\PHPUnitReporter\Core\Reporter\ReporterFactory;
use Tiden\PHPUnitReporter\Core\Reporter\RunReporter;
use Tiden\PHPUnitReporter\Core\Uuid;
use Tiden\PHPUnitReporter\Tests\Support\FakeTransport;
use Tiden\PHPUnitReporter\Tests\Support\RecordingLogger;

final class ReporterFactoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/tiden-factory-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        @unlink($this->directory.'/state.json');
        @rmdir($this->directory);
    }

    public function test_tiden_mode_produces_a_reporting_reporter(): void
    {
        $reporter = $this->create([
            'TIDEN_MODE' => 'tiden',
            'TIDEN_API_TOKEN' => 'tfy_x',
            'TIDEN_PRODUCT_ID' => 'p1',
            'TIDEN_BASE_URL' => 'https://api.tiden.ai',
        ]);

        $this->assertInstanceOf(RunReporter::class, $reporter);
    }

    public function test_report_mode_writes_to_disk_instead(): void
    {
        $reporter = $this->create(['TIDEN_MODE' => 'report', 'TIDEN_REPORT_CONNECTION_PATH' => $this->directory]);

        $this->assertInstanceOf(FileReporter::class, $reporter);
    }

    public function test_an_unconfigured_reporter_is_a_no_op(): void
    {
        $this->assertInstanceOf(NullReporter::class, $this->create([]));
    }

    /**
     * Silence for the developer who never opted in. Announcing unconditionally
     * would print one line per ParaTest worker on every local run.
     */
    public function test_says_nothing_when_the_user_set_nothing(): void
    {
        $logger = new RecordingLogger;
        $this->create([], $logger);

        $this->assertSame([], $logger->lines);
    }

    /**
     * And a clear line for the user who did opt in but got it wrong — the
     * failure mode commons wrote its own rationale about.
     */
    public function test_explains_itself_when_the_user_meant_to_report(): void
    {
        $logger = new RecordingLogger;
        $this->create(['TIDEN_MODE' => 'tiden', 'TIDEN_PRODUCT_ID' => 'p1'], $logger);

        $this->assertTrue($logger->has('INFO', 'reporter disabled'));
        $this->assertTrue($logger->has('INFO', 'TIDEN_API_TOKEN'));
    }

    public function test_falls_back_to_the_configured_mode_when_the_primary_one_cannot_run(): void
    {
        $logger = new RecordingLogger;

        $reporter = $this->create([
            'TIDEN_MODE' => 'tiden',
            'TIDEN_FALLBACK' => 'report',
            'TIDEN_REPORT_CONNECTION_PATH' => $this->directory,
        ], $logger);

        $this->assertInstanceOf(FileReporter::class, $reporter, 'results are captured rather than dropped');
        $this->assertTrue($logger->has('INFO', 'falling back to "report" mode'));
    }

    public function test_a_fallback_that_is_itself_unusable_does_not_mask_the_real_problem(): void
    {
        $logger = new RecordingLogger;

        // "report" without a path cannot run either, so the user must still be
        // told what is actually wrong with their primary mode.
        $reporter = $this->create(['TIDEN_MODE' => 'tiden', 'TIDEN_FALLBACK' => 'report'], $logger);

        $this->assertInstanceOf(NullReporter::class, $reporter);
        $this->assertTrue($logger->has('INFO', 'TIDEN_API_TOKEN'));
    }

    private const TIDEN = [
        'TIDEN_MODE' => 'tiden',
        'TIDEN_API_TOKEN' => 'tfy_x',
        'TIDEN_PRODUCT_ID' => 'p1',
        'TIDEN_BASE_URL' => 'https://api.tiden.ai',
    ];

    public function test_the_repository_reaches_the_run_and_every_result(): void
    {
        $transport = $this->tidenTransport();

        $this->runOneResult(['TIDEN_REPOSITORY' => 'https://github.com/acme/shop'], [], $transport);

        $run = json_decode($transport->requests[0]['json'], true);
        $this->assertSame('https://github.com/acme/shop', $run['clientMeta']['gitRepository']);

        $result = json_decode($transport->requests[1]['json'], true)['results'][0];
        $this->assertSame(
            ['language' => 'php', 'repository' => 'https://github.com/acme/shop', 'scope' => ['App', 'FooTest'], 'name' => ['testBar']],
            $result['identity'],
        );
    }

    public function test_the_repository_comes_from_the_ci_when_nothing_is_set(): void
    {
        $transport = $this->tidenTransport();

        $this->runOneResult([], ['CI_SERVER_URL' => 'https://gitlab.com', 'CI_PROJECT_PATH' => 'acme/shop'], $transport);

        $result = json_decode($transport->requests[1]['json'], true)['results'][0];
        $this->assertSame('https://gitlab.com/acme/shop', $result['identity']['repository']);
    }

    public function test_the_repository_comes_from_the_checkout_of_the_root_dir(): void
    {
        mkdir($this->directory.'/.git');
        file_put_contents($this->directory.'/.git/config', "[remote \"origin\"]\n\turl = git@github.com:acme/shop.git\n");
        $transport = $this->tidenTransport();

        $this->runOneResult([], [], $transport);

        $result = json_decode($transport->requests[1]['json'], true)['results'][0];
        $this->assertSame('git@github.com:acme/shop.git', $result['identity']['repository']);

        unlink($this->directory.'/.git/config');
        rmdir($this->directory.'/.git');
    }

    /**
     * No repository: the parts cannot be placed, so they are not sent, and the
     * user is told once. The signature still identifies every case.
     */
    public function test_without_a_repository_no_identity_is_sent_and_one_warning_is_logged(): void
    {
        $transport = $this->tidenTransport();
        $logger = new RecordingLogger;

        $this->runOneResult([], [], $transport, $logger, results: 3);

        $run = json_decode($transport->requests[0]['json'], true);
        $this->assertArrayNotHasKey('gitRepository', $run['clientMeta']);

        foreach (json_decode($transport->requests[1]['json'], true)['results'] as $result) {
            $this->assertArrayNotHasKey('identity', $result);
            $this->assertSame('php/v3::app::footest::testbar', $result['signature']);
        }

        $warnings = array_filter($logger->lines, static fn (string $line): bool => str_starts_with($line, 'WARN ') && str_contains($line, 'TIDEN_REPOSITORY'));
        $this->assertCount(1, $warnings);
    }

    public function test_a_disabled_reporter_does_not_look_for_a_repository(): void
    {
        $logger = new RecordingLogger;

        (new ReporterFactory(new FakeTransport, env: []))->create(
            (new ConfigResolver)->resolve([], [], $this->directory),
            $logger,
            new FilePathResolver($this->directory),
        );

        $this->assertSame([], $logger->lines);
    }

    /**
     * @param  array<string, string>  $env
     * @param  array<string, string>  $ci
     */
    private function runOneResult(array $env, array $ci, FakeTransport $transport, ?RecordingLogger $logger = null, int $results = 1): void
    {
        $state = $this->directory.'/state.json';
        $resolution = (new ConfigResolver)->resolve([...self::TIDEN, ...$env, 'TIDEN_STATE_FILE' => $state], [], $this->directory);

        $reporter = (new ReporterFactory($transport, env: $ci))->create(
            $resolution,
            $logger ?? new RecordingLogger,
            new FilePathResolver($this->directory),
        );

        $reporter->startRun();

        for ($i = 0; $i < $results; $i++) {
            $reporter->addResult(new TestResult(
                id: Uuid::v4(),
                signature: 'php/v3::app::footest::testbar',
                title: 'testBar',
                suitePath: [new SuiteSegment('App')],
                startTime: 1_800_000_000.0,
                identity: IdentityParts::forMethod('App\\FooTest', 'testBar'),
            ));
        }

        $reporter->complete();

        @unlink($state);
        putenv('TIDEN_RUN_ID');
    }

    private function tidenTransport(): FakeTransport
    {
        return new FakeTransport([
            new HttpResponse(200, '{"run":{"seqNum":5}}'),
            new HttpResponse(200, '{"accepted":"3"}'),
        ]);
    }

    /** @param array<string, string> $env */
    private function create(array $env, ?RecordingLogger $logger = null): object
    {
        $resolution = (new ConfigResolver)->resolve($env, [], $this->directory);

        return (new ReporterFactory(new FakeTransport))->create(
            $resolution,
            $logger ?? new RecordingLogger,
            new FilePathResolver($this->directory),
        );
    }
}
