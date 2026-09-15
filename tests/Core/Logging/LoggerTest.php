<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core\Logging;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Config\Config;
use Tiden\PHPUnitReporter\Core\Config\LoggingConfig;
use Tiden\PHPUnitReporter\Core\Config\Mode;
use Tiden\PHPUnitReporter\Core\Logging\Logger;

final class LoggerTest extends TestCase
{
    private string $cwd;

    private string $sandbox;

    protected function setUp(): void
    {
        // The log path is relative to the working directory, so the test needs
        // its own rather than writing into the package checkout.
        $this->cwd = getcwd() ?: '.';
        $this->sandbox = sys_get_temp_dir().'/tiden-logger-'.bin2hex(random_bytes(4));
        mkdir($this->sandbox, 0o775, true);
        chdir($this->sandbox);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        @unlink($this->sandbox.'/logs/tiden.log');
        @rmdir($this->sandbox.'/logs');
        @rmdir($this->sandbox);
    }

    /**
     * The failure this package shipped with: under ParaTest the runner reads a
     * worker's stderr only when that worker crashes, so on a green run every
     * error line was discarded. A lost batch then had no channel at all.
     */
    #[DataProvider('escalationCases')]
    public function test_the_log_file_is_written_when_the_console_cannot_be_heard(
        bool $configuredFile,
        bool $consoleIsDiscarded,
        bool $expectFile,
    ): void {
        $config = new Config(
            mode: Mode::Tiden,
            logging: new LoggingConfig(console: false, file: $configuredFile),
        );

        Logger::fromConfig($config, $consoleIsDiscarded)->error('a batch was lost');

        $this->assertSame($expectFile, is_file($this->sandbox.'/logs/tiden.log'));
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function escalationCases(): iterable
    {
        yield 'paratest_escalates_without_being_asked' => [false, true, true];
        yield 'paratest_with_the_file_already_on_still_writes' => [true, true, true];
        yield 'a_plain_run_honours_the_configured_file' => [true, false, true];
        yield 'a_plain_run_writes_nothing_by_default' => [false, false, false];
    }

    /**
     * A disabled reporter must leave nothing behind. It announces that it is
     * off as soon as ANY TIDEN_* variable is set — and a test harness may set
     * one for unrelated reasons, e.g. pinning TIDEN_ROOT_DIR inside a
     * container. Escalating that announcement to disk put an untracked file in
     * the checkout of everyone who had never asked for Tiden at all.
     */
    public function test_a_disabled_reporter_writes_no_file_even_under_paratest(): void
    {
        $config = new Config(mode: Mode::Off, logging: new LoggingConfig(console: false, file: false));

        Logger::fromConfig($config, true)->info('reporter disabled — nothing will be reported');

        $this->assertFalse(is_file($this->sandbox.'/logs/tiden.log'));
    }

    /** An explicit TIDEN_LOGGING_FILE still wins, disabled or not. */
    public function test_an_explicit_file_request_is_honoured_even_when_disabled(): void
    {
        $config = new Config(mode: Mode::Off, logging: new LoggingConfig(console: false, file: true));

        Logger::fromConfig($config, true)->info('reporter disabled');

        $this->assertTrue(is_file($this->sandbox.'/logs/tiden.log'));
    }

    public function test_the_escalated_file_carries_the_message(): void
    {
        $config = new Config(mode: Mode::Tiden, logging: new LoggingConfig(console: false, file: false));

        Logger::fromConfig($config, true)->error('failed to report 200 result(s)');

        $this->assertStringContainsString(
            'failed to report 200 result(s)',
            (string) file_get_contents($this->sandbox.'/logs/tiden.log'),
        );
    }
}
