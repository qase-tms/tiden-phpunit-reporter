<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core\Client;

use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Client\HttpResponse;
use Tiden\PHPUnitReporter\Core\Client\TidenApi;
use Tiden\PHPUnitReporter\Core\Exception\ApiException;
use Tiden\PHPUnitReporter\Tests\Support\FakeTransport;

final class TidenApiTest extends TestCase
{
    public function test_create_run_reads_seq_num_from_the_response_field(): void
    {
        $transport = FakeTransport::respondingWith(200, '{"run":{"seqNum":42,"id":"abc"}}');

        $this->assertSame(42, $this->api($transport)->createRun(['title' => 'x']));
    }

    public function test_create_run_accepts_seq_num_as_a_string(): void
    {
        // int64 fields come back as JSON strings on some paths.
        $transport = FakeTransport::respondingWith(200, '{"run":{"seqNum":"7"}}');

        $this->assertSame(7, $this->api($transport)->createRun([]));
    }

    /**
     * The bridge this package replaces scanned CLI output for "the last integer
     * in stdout", which matched a fragment of a date and would have uploaded a
     * whole run's results into run 9. There is no fallback here on purpose:
     * failing is better than reporting into someone else's run.
     */
    public function test_create_run_fails_rather_than_guessing_a_run_number(): void
    {
        $transport = FakeTransport::respondingWith(200, '{"message":"created run 9 on 2026-09-10"}');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('did not return a usable run.seqNum');

        $this->api($transport)->createRun([]);
    }

    public function test_authorization_uses_a_bearer_token(): void
    {
        $transport = FakeTransport::respondingWith(200, '{"run":{"seqNum":1}}');
        $this->api($transport)->createRun([]);

        $this->assertSame('Bearer tfy_secret', $transport->requests[0]['headers']['Authorization']);
    }

    public function test_product_id_is_url_encoded_into_the_path(): void
    {
        $transport = FakeTransport::respondingWith(200, '{"run":{"seqNum":1}}');

        (new TidenApi('https://api.tiden.ai/', 'tfy_secret', 'a b/c', $transport))->createRun([]);

        $this->assertSame('https://api.tiden.ai/v1/products/a%20b%2Fc/runs', $transport->requests[0]['url']);
    }

    public function test_complete_run_sends_an_empty_json_object_not_an_array(): void
    {
        // CompleteTestRunBody is an empty object, but the body is required; a
        // bare [] would serialise as a JSON array and be rejected.
        $transport = FakeTransport::respondingWith(200, '{}');

        $this->api($transport)->completeRun(5);

        $this->assertSame('{}', $transport->requests[0]['json']);
        $this->assertStringEndsWith('/runs/5:complete', $transport->requests[0]['url']);
    }

    public function test_report_results_returns_accepted_and_duplicate_counts(): void
    {
        $transport = FakeTransport::respondingWith(200, '{"status":true,"accepted":"3","duplicates":"1"}');

        $this->assertSame(
            ['accepted' => 3, 'duplicates' => 1],
            $this->api($transport)->reportResults(5, [['id' => 'x']]),
        );
    }

    public function test_retries_only_on429_and_then_succeeds(): void
    {
        $transport = new FakeTransport([
            new HttpResponse(429, '{}', ['retry-after' => '1']),
            new HttpResponse(429, '{}'),
            new HttpResponse(200, '{"accepted":"1","duplicates":"0"}'),
        ]);

        $slept = [];
        $api = $this->api($transport, static function (int $ms) use (&$slept): void {
            $slept[] = $ms;
        });

        $api->reportResults(1, [['id' => 'x']]);

        $this->assertCount(3, $transport->requests);
        // Retry-After (1s) governs the first wait; the second 429 carries no
        // header, so the exponential backoff that has meanwhile doubled applies.
        $this->assertSame([1000, 2000], $slept);
    }

    public function test_does_not_retry_on400(): void
    {
        $transport = new FakeTransport([new HttpResponse(400, '{"errors":[{"index":0,"code":"INVALID_RESULT_ID"}]}')]);

        try {
            $this->api($transport)->reportResults(1, [['id' => 'not-a-uuid']]);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->statusCode);
        }

        $this->assertCount(1, $transport->requests, 'a rejected batch is not retried four more times');
    }

    public function test_unauthorized_failure_points_at_the_token(): void
    {
        $transport = new FakeTransport([new HttpResponse(401, '{}')]);

        $this->expectExceptionMessage('Check TIDEN_API_TOKEN');

        $this->api($transport)->completeRun(1);
    }

    private function api(FakeTransport $transport, ?callable $sleeper = null): TidenApi
    {
        return new TidenApi(
            'https://api.tiden.ai',
            'tfy_secret',
            'prod-1',
            $transport,
            null,
            $sleeper === null ? static function (int $ms): void {} : \Closure::fromCallable($sleeper),
        );
    }
}
