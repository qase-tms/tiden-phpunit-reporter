<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Logging;

use Tiden\PHPUnitReporter\Core\Config\Config;

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

    public static function fromConfig(Config $config): self
    {
        return new self($config->logging->console, $config->logging->file, $config->debug);
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
