<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Client;

/** The single seam the tests replace; everything above it is transport-agnostic. */
interface Transport
{
    /** @param array<string, string> $headers */
    public function post(string $url, string $json, array $headers): HttpResponse;
}
