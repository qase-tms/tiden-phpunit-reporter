<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Transform;

use Tiden\PHPUnitReporter\Core\Model\Status;
use Tiden\PHPUnitReporter\Core\Model\TestResult;

/**
 * TestResult -> the ResultCreate JSON body from public-api/v1/openapi.yaml.
 *
 * Three shapes here are easy to get wrong and are checked by the contract test:
 *  - execution.startTime / endTime are FRACTIONAL EPOCH SECONDS as a number,
 *    not milliseconds and not an RFC3339 string;
 *  - execution.duration is an int64 sent AS A STRING, in milliseconds;
 *  - suitePath entries are OBJECTS {title}, root to leaf, never bare strings.
 *
 * externalId is deliberately never sent. No JS reporter sends it either, so
 * identity resolves through the signature branch and a live-doc-born case
 * carries external_id "s:<signature>". Sending a wrong externalId here would be
 * a permanent duplicate, because ingest keys on external_id first.
 */
final class ResultTransformer
{
    /** @param array<string, string> $statusMapping */
    public function __construct(private readonly array $statusMapping = []) {}

    /**
     * @return array<string, mixed>
     */
    public function toResultCreate(TestResult $result): array
    {
        $status = $this->mapStatus($result->status);

        $execution = [
            'status' => $status->value,
            'startTime' => round($result->startTime, 6),
            'duration' => (string) $result->durationMs(),
        ];

        if ($result->endTime !== null) {
            $execution['endTime'] = round($result->endTime, 6);
        }

        if ($result->stacktrace !== null && $result->stacktrace !== '') {
            $execution['stacktrace'] = $result->stacktrace;
        }

        if ($result->thread !== null && $result->thread !== '') {
            $execution['thread'] = $result->thread;
        }

        $fields = $result->fields;

        if ($result->filePath !== null) {
            $fields['file_path'] = $result->filePath;
        }

        if ($result->tags !== []) {
            $fields['tags'] = implode(',', array_values(array_unique($result->tags)));
        }

        $body = [
            'id' => $result->id,
            'title' => $result->title,
            'signature' => $result->signature,
            'execution' => $execution,
            'suitePath' => array_map(
                static fn ($segment): array => ['title' => $segment->title],
                $result->suitePath,
            ),
            'defect' => false,
        ];

        if ($fields !== []) {
            $body['fields'] = $fields;
        }

        if ($result->params !== []) {
            $body['params'] = $result->params;
        }

        if ($result->message !== null && $result->message !== '') {
            $body['message'] = $result->message;
        }

        return $body;
    }

    /** TIDEN_STATUS_MAPPING, applied last so it can override anything upstream decided. */
    public function mapStatus(Status $status): Status
    {
        $mapped = $this->statusMapping[$status->value] ?? null;

        return Status::tryFromString($mapped) ?? $status;
    }
}
