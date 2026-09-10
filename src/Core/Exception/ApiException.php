<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Exception;

final class ApiException extends TidenException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $body = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
