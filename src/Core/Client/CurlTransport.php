<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Client;

use Tiden\PHPUnitReporter\Core\Exception\ApiException;

final class CurlTransport implements Transport
{
    public function __construct(private readonly int $timeoutSeconds = 30) {}

    /** @param array<string, string> $headers */
    public function post(string $url, string $json, array $headers): HttpResponse
    {
        $handle = curl_init();

        if ($handle === false) {
            throw new ApiException('Could not initialise curl.');
        }

        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = $name.': '.$value;
        }

        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $formatted,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function ($_handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        if ($errorNumber !== 0 || ! is_string($body)) {
            throw new ApiException(sprintf('HTTP request to %s failed: %s', $url, $error !== '' ? $error : 'unknown curl error'));
        }

        /** @var array<string, string> $responseHeaders */
        return new HttpResponse($status, $body, $responseHeaders);
    }
}
