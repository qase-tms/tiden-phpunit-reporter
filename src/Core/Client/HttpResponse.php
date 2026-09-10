<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Client;

final class HttpResponse
{
    /** @param array<string, string> $headers Lower-cased header names. */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {}

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($this->body, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }
}
