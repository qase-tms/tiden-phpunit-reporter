<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core\Reporter;

use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Config\ConfigResolver;
use Tiden\PHPUnitReporter\Core\Identity\FilePathResolver;
use Tiden\PHPUnitReporter\Core\Reporter\FileReporter;
use Tiden\PHPUnitReporter\Core\Reporter\NullReporter;
use Tiden\PHPUnitReporter\Core\Reporter\ReporterFactory;
use Tiden\PHPUnitReporter\Core\Reporter\RunReporter;
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
