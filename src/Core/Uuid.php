<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core;

/**
 * RFC 4122 v4, from random_bytes. No ramsey/uuid dependency.
 *
 * A result's id is the API's idempotency key and IS VALIDATED AS A UUID. The
 * Vitest reporter shipped a framework-native id here first; every result was
 * rejected with INVALID_RESULT_ID and the run sat at total=0 while looking like
 * it had reported. Do not substitute a "unique enough" string.
 */
final class Uuid
{
    public static function v4(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
