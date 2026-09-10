<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Support;

use Tiden\PHPUnitReporter\Core\Client\HttpResponse;
use Tiden\PHPUnitReporter\Core\Client\Transport;

/** Records requests and replays queued responses, so no test touches the network. */
final class FakeTransport implements Transport
{
    /** @var list<array{url: string, json: string, headers: array<string, string>}> */
    public array $requests = [];

    /** @param list<HttpResponse> $responses Replayed in order; the last one repeats. */
    public function __construct(private array $responses = []) {}

    public static function respondingWith(int $status, string $body = '{}', array $headers = []): self
    {
        return new self([new HttpResponse($status, $body, $headers)]);
    }

    public function post(string $url, string $json, array $headers): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'json' => $json, 'headers' => $headers];

        if ($this->responses === []) {
            return new HttpResponse(200, '{}');
        }

        return count($this->responses) === 1 ? $this->responses[0] : array_shift($this->responses);
    }

    /**
     * Matches on the END of the URL, not anywhere in it: "/runs" must count
     * CreateTestRun only, and "/runs/11:complete" also contains "/runs".
     */
    public function countRequestsTo(string $suffix): int
    {
        return count(array_filter($this->requests, static fn (array $r): bool => str_ends_with($r['url'], $suffix)));
    }

    /** @return array<string, mixed> */
    public function lastBody(): array
    {
        $last = end($this->requests);

        /** @var array<string, mixed> */
        return $last === false ? [] : (array) json_decode($last['json'], true);
    }
}
