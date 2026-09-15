<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core;

/**
 * The two UTF-8 operations this package needs, without ext-mbstring.
 *
 * mbstring is not a dependency and is not going to become one for this: the
 * package requires only ext-curl and ext-json today, and Signature deliberately
 * lowercases ASCII-only for the same reason.
 */
final class Utf8
{
    /**
     * Truncate to a BYTE budget, cutting only on a character boundary.
     *
     * A byte-indexed substr() through the middle of a multi-byte sequence
     * produces malformed UTF-8. json_encode refuses a body containing it, so
     * one truncated value used to cost every result it was batched with.
     */
    public static function truncate(string $value, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }

        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $cut = substr($value, 0, $maxBytes);
        $end = strlen($cut);

        // Continuation bytes are 10xxxxxx: walk back to the sequence's lead.
        while ($end > 0 && (ord($cut[$end - 1]) & 0xC0) === 0x80) {
            $end--;
        }

        if ($end === 0) {
            return '';
        }

        $lead = ord($cut[$end - 1]);
        $length = match (true) {
            ($lead & 0x80) === 0x00 => 1,
            ($lead & 0xE0) === 0xC0 => 2,
            ($lead & 0xF0) === 0xE0 => 3,
            ($lead & 0xF8) === 0xF0 => 4,
            // Not a lead byte at all. Keeping it is safe: encoding substitutes it.
            default => 1,
        };

        // The sequence fits inside the budget, so the earlier walk-back was over
        // a complete character and the whole cut is kept.
        return $end - 1 + $length <= strlen($cut) ? $cut : substr($cut, 0, $end - 1);
    }
}
