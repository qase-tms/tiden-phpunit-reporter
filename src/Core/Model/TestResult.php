<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Model;

/**
 * One attempt. Built when PHPUnit prepares a test, mutated by whichever outcome
 * event fires, and flushed when the test finishes.
 *
 * Mutable by design: PHPUnit reports the outcome in a different event from the
 * one that starts and the one that ends the test.
 */
final class TestResult
{
    /** @var array<string, string> */
    public array $params = [];

    /** @var array<string, string> */
    public array $fields = [];

    /** @var list<string> */
    public array $tags = [];

    public Status $status = Status::Passed;

    public ?string $message = null;

    public ?string $stacktrace = null;

    public ?float $endTime = null;

    /**
     * @param  string  $id  UUID; the API's idempotency key.
     * @param  list<SuiteSegment>  $suitePath  Root to leaf.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $signature,
        public string $title,
        public array $suitePath,
        public readonly float $startTime,
        public readonly ?string $filePath = null,
        public readonly ?string $thread = null,
    ) {}

    public function durationMs(): int
    {
        if ($this->endTime === null) {
            return 0;
        }

        return max(0, (int) round(($this->endTime - $this->startTime) * 1000));
    }

    public function appendMessage(string $text): void
    {
        $this->message = $this->message === null || $this->message === ''
            ? $text
            : $this->message."\n".$text;
    }
}
