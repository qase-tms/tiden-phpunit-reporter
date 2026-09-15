<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Logging;

use Tiden\PHPUnitReporter\Core\Config\Config;
use Tiden\PHPUnitReporter\Core\Config\Mode;

/**
 * Writes to stderr so reporter chatter never contaminates a test runner's
 * stdout (TAP/JUnit consumers read that), plus an optional log file.
 */
class Logger
{
    private const PREFIX = 'tiden: ';

    /** @var list<string> */
    private array $seenOnce = [];

    public function __construct(
        private readonly bool $console = true,
        private readonly bool $file = false,
        private readonly bool $debug = false,
        private readonly string $logPath = 'logs/tiden.log',
    ) {}

    /**
     * @param  bool|null  $consoleIsDiscarded  Overridable for tests; detected from the
     *                                         environment when null.
     */
    public static function fromConfig(Config $config, ?bool $consoleIsDiscarded = null): self
    {
        $consoleIsDiscarded ??= self::runningUnderParaTest();

        // Escalate to the log file on our own when the console cannot be heard.
        //
        // ParaTest gives each worker a pipe for stdout and stderr that the runner
        // reads only if that worker CRASHES; on a green run the buffer is
        // discarded unread. So every line this logger wrote about a failed
        // upload went nowhere, and a whole batch could go missing with no signal
        // on either side of the wire. Turning the file on here rather than
        // asking each consuming repository to set TIDEN_LOGGING_FILE keeps the
        // fix in one place: every ParaTest user has this problem, and none of
        // them can see it.
        //
        // Only when the reporter is actually switched on, though. A disabled
        // reporter has no results to lose and nothing to say that is worth a
        // file in someone's checkout -- and it DOES say something: it announces
        // that it is disabled as soon as any TIDEN_* variable is set, which a
        // test harness may well set for unrelated reasons.
        $file = $config->logging->file || ($consoleIsDiscarded && $config->mode !== Mode::Off);

        return new self($config->logging->console, $file, $config->debug);
    }

    /** ParaTest labels its workers with TEST_TOKEN; nothing else in a PHPUnit run sets it. */
    private static function runningUnderParaTest(): bool
    {
        $token = getenv('TEST_TOKEN');

        return is_string($token) && trim($token) !== '';
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warning(string $message): void
    {
        $this->write('WARN', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    public function debug(string $message): void
    {
        if ($this->debug) {
            $this->write('DEBUG', $message);
        }
    }

    /**
     * Emit a message at most once per process. Under ParaTest every worker is
     * its own process, so this dedupes within a worker, not across the run.
     */
    public function warningOnce(string $key, string $message): void
    {
        if (in_array($key, $this->seenOnce, true)) {
            return;
        }

        $this->seenOnce[] = $key;
        $this->warning($message);
    }

    /** Never let a token reach a log line, a log file, or a CI transcript. */
    public static function mask(?string $secret): string
    {
        if ($secret === null || $secret === '') {
            return '(unset)';
        }

        if (strlen($secret) <= 8) {
            return str_repeat('*', strlen($secret));
        }

        return substr($secret, 0, 4).str_repeat('*', strlen($secret) - 8).substr($secret, -4);
    }

    protected function write(string $level, string $message): void
    {
        $line = sprintf('[%s] %s%s', $level, self::PREFIX, $message);

        if ($this->console) {
            file_put_contents('php://stderr', $line.PHP_EOL);
        }

        if ($this->file) {
            $directory = dirname($this->logPath);

            if (! is_dir($directory)) {
                @mkdir($directory, 0o775, true);
            }

            @file_put_contents(
                $this->logPath,
                sprintf('[%s] %s', date('c'), $line).PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
        }
    }
}
