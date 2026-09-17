<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Client;

use Tiden\PHPUnitReporter\Core\Exception\ApiException;
use Tiden\PHPUnitReporter\Core\Logging\Logger;

/**
 * The three public Test Runs API operations this reporter needs.
 *
 * Hand-written rather than generated: there is no published PHP tiden/api-client
 * (tiden-specs' sdk/php.yml generates into a gitignored directory and the client
 * sync workflow has rows for Go and TypeScript only), and commons hand-writes
 * its own DTOs for the same reason. The contract test in tests/Contract is what
 * keeps this honest against public-api/v1/openapi.yaml.
 */
final class TidenApi
{
    private const MAX_RETRIES = 5;

    private const BACKOFF_START_MS = 1000;

    private const BACKOFF_CAP_MS = 30_000;

    /**
     * Statuses worth sending the same body again for.
     *
     * 429 alone was not enough: the results endpoint carries no application
     * rate limit, so in practice that set meant "never retry" while a single
     * 503 from a proxy dropped a whole batch permanently.
     *
     * 500 belongs here, though it reads like it should not. An unexpected
     * server fault is not a considered refusal, and in the field it is
     * intermittent: one batch in fifty fails while the rest of the same run
     * succeeds. Resending is safe because every result carries a UUID the API
     * treats as an idempotency key, so a batch that did land is deduplicated
     * rather than doubled. A batch lost to a 500 is 200 results that the suite
     * proved and nobody can see.
     *
     * What stays out is the deterministic 4xx: a rejected payload, a bad token,
     * a completed run. Those are the same answer however many times you ask.
     *
     * Connection-level failures are retried too, but they are not in this list:
     * they never produce a status at all. See send().
     *
     * @var list<int>
     */
    private const RETRYABLE_STATUSES = [408, 429, 500, 502, 503, 504];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly string $productId,
        private readonly Transport $transport = new CurlTransport,
        private readonly ?Logger $logger = null,
        /** Injectable so tests do not actually sleep through five backoffs. */
        private readonly ?\Closure $sleeper = null,
    ) {}

    /**
     * @param  array<string, mixed>  $body  CreateTestRunBody
     * @return int The run's seq_num — the id every other run endpoint addresses.
     */
    public function createRun(array $body): int
    {
        $response = $this->send($this->url('/runs'), $body);
        $decoded = $response->json();

        /** @var array<string, mixed> $run */
        $run = is_array($decoded['run'] ?? null) ? $decoded['run'] : [];
        $seqNum = $run['seqNum'] ?? null;

        // Read the field, never parse it out of prose. The bridge this package
        // replaces scanned CLI output for "the last integer" and would have
        // uploaded a whole run into run 9.
        if (is_string($seqNum) && preg_match('/^\d+$/', $seqNum)) {
            $seqNum = (int) $seqNum;
        }

        if (! is_int($seqNum) || $seqNum <= 0) {
            throw new ApiException(
                'CreateTestRun did not return a usable run.seqNum.',
                $response->status,
                $response->body,
            );
        }

        return $seqNum;
    }

    /**
     * @param  list<array<string, mixed>>  $results  1..2000 ResultCreate entries.
     * @return array{accepted: int, duplicates: int}
     */
    public function reportResults(int $runSeq, array $results): array
    {
        $response = $this->send($this->url(sprintf('/runs/%d/results:report', $runSeq)), ['results' => $results]);
        $decoded = $response->json();

        // int64 fields arrive as strings.
        $accepted = (int) ($decoded['accepted'] ?? 0);
        $duplicates = (int) ($decoded['duplicates'] ?? 0);

        $this->logger?->debug(sprintf(
            'reported %d result(s) to run %d: accepted=%d duplicates=%d',
            count($results),
            $runSeq,
            $accepted,
            $duplicates,
        ));

        return ['accepted' => $accepted, 'duplicates' => $duplicates];
    }

    /** Idempotent server-side: completing an already-completed run returns it unchanged. */
    public function completeRun(int $runSeq): void
    {
        // CompleteTestRunBody is an empty object but the body is required.
        $this->send($this->url(sprintf('/runs/%d:complete', $runSeq)), []);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/v1/products/'.rawurlencode($this->productId).$path;
    }

    /** @param array<string, mixed> $body */
    private function send(string $url, array $body): HttpResponse
    {
        // INVALID_UTF8_SUBSTITUTE, not a bare throw: a single malformed byte —
        // from a truncated message, a stacktrace, a data-provider rendering —
        // costs one character, not the whole batch it was travelling with.
        // The server rejects invalid UTF-8 at its edge anyway, and it does so
        // without logging, so sending it is never the better option.
        $json = json_encode(
            $body === [] ? new \stdClass : $body,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        $headers = [
            'Authorization' => 'Bearer '.$this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $backoffMs = self::BACKOFF_START_MS;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = $this->transport->post($url, $json, $headers);
            } catch (ApiException $e) {
                // A refused, reset or timed-out connection never produced a
                // status to inspect, so the status-based branch below cannot
                // see it -- and not retrying it drops a whole batch for a blip.
                // This is the most transient failure there is and the one least
                // worth losing results over: it never reached the server, so
                // there is no record of it on either side of the wire.
                if ($attempt >= self::MAX_RETRIES) {
                    throw $e;
                }

                $this->sleepMs($backoffMs);
                $backoffMs = min($backoffMs * 2, self::BACKOFF_CAP_MS);

                continue;
            }

            if ($response->isSuccess()) {
                return $response;
            }

            if (in_array($response->status, self::RETRYABLE_STATUSES, true) && $attempt < self::MAX_RETRIES) {
                $this->sleepMs($this->retryDelayMs($response, $backoffMs));
                $backoffMs = min($backoffMs * 2, self::BACKOFF_CAP_MS);

                continue;
            }

            throw $this->describeFailure($url, $response);
        }

        throw new ApiException(sprintf('Gave up on %s after %d attempts.', $url, self::MAX_RETRIES + 1));
    }

    private function retryDelayMs(HttpResponse $response, int $backoffMs): int
    {
        $retryAfter = $response->headers['retry-after'] ?? null;

        if ($retryAfter !== null && preg_match('/^\d+$/', trim($retryAfter))) {
            return min((int) trim($retryAfter) * 1000, self::BACKOFF_CAP_MS);
        }

        return $backoffMs;
    }

    private function describeFailure(string $url, HttpResponse $response): ApiException
    {
        $decoded = $response->json();

        // A 400 on results:report carries per-entry errors. Print them: "the
        // batch was rejected" without saying which entry is unactionable.
        foreach ($this->reportErrors($decoded) as $error) {
            $this->logger?->error(sprintf(
                'Result #%s (id=%s) rejected: %s: %s',
                (string) ($error['index'] ?? '?'),
                (string) ($error['resultId'] ?? '?'),
                (string) ($error['code'] ?? 'UNKNOWN'),
                (string) ($error['message'] ?? ''),
            ));
        }

        $hint = match ($response->status) {
            401 => ' Check TIDEN_API_TOKEN.',
            403 => ' The token is valid but not allowed to write to this product.',
            404 => ' Check TIDEN_PRODUCT_ID and TIDEN_BASE_URL.',
            default => '',
        };

        // The server's own reason lives in the top-level message; without it a
        // user is told "HTTP 400" when the API said "run #7 is failed; results
        // are locked". Per-entry errors above are additional, not a substitute.
        $message = $decoded['message'] ?? null;

        if (is_string($message) && $message !== '') {
            $hint = ' '.$message.$hint;
        }

        return new ApiException(
            sprintf('POST %s failed with HTTP %d.%s', $url, $response->status, $hint),
            $response->status,
            $response->body,
        );
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return list<array<string, mixed>>
     */
    private function reportErrors(array $decoded): array
    {
        $errors = [];

        if (is_array($decoded['errors'] ?? null)) {
            /** @var list<mixed> $candidates */
            $candidates = $decoded['errors'];

            foreach ($candidates as $error) {
                if (is_array($error)) {
                    /** @var array<string, mixed> $error */
                    $errors[] = $error;
                }
            }
        }

        return $errors;
    }

    private function sleepMs(int $milliseconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($milliseconds);

            return;
        }

        usleep($milliseconds * 1000);
    }
}
