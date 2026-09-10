<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Support;

use Tiden\PHPUnitReporter\Core\Run\ProcessProbe;

/**
 * Answers about processes without needing them to exist — or ext-posix to be
 * loaded — so both branches can be exercised in one run.
 */
final class FakeProcessProbe implements ProcessProbe
{
    /** @param array<int, bool|null> $answers pid => running; $default covers the rest. */
    public function __construct(
        private readonly array $answers = [],
        private readonly ?bool $default = true,
    ) {}

    /** Nothing can be established, as when ext-posix is absent. */
    public static function unableToTell(): self
    {
        return new self([], null);
    }

    /** @param list<int> $pids */
    public static function allGoneExcept(array $pids): self
    {
        return new self(array_fill_keys($pids, true), false);
    }

    public function isRunning(int $pid): ?bool
    {
        return array_key_exists($pid, $this->answers) ? $this->answers[$pid] : $this->default;
    }
}
