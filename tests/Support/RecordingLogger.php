<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Support;

use Tiden\PHPUnitReporter\Core\Logging\Logger;

/** Captures lines instead of writing them, so tests can assert on what a user is told. */
final class RecordingLogger extends Logger
{
    /** @var list<string> */
    public array $lines = [];

    public function __construct()
    {
        parent::__construct(console: false, file: false, debug: true);
    }

    protected function write(string $level, string $message): void
    {
        $this->lines[] = $level.' '.$message;
    }

    public function has(string $level, string $needle): bool
    {
        foreach ($this->lines as $line) {
            if (str_starts_with($line, $level.' ') && str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function joined(): string
    {
        return implode("\n", $this->lines);
    }
}
