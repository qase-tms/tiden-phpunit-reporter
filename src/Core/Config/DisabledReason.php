<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Config;

/**
 * Why the reporter will not report, in words a human can act on.
 *
 * commons deliberately announces this instead of failing silently
 * (`commons/src/tiden.ts`, logReporterDisabled) — a silent no-op costs people
 * hours. We announce too, but only when the user showed intent by setting at
 * least one TIDEN_* variable; a developer who has never heard of Tiden running
 * the suite locally sees nothing at all.
 */
final class DisabledReason
{
    public function __construct(
        public readonly string $reason,
        public readonly string $remedy,
    ) {}

    public static function modeOff(): self
    {
        return new self(
            'mode is "off"',
            'set TIDEN_MODE=tiden (or "report" to write results to disk)',
        );
    }

    public static function missingSetting(string $configKey, string $envName): self
    {
        return new self(
            sprintf('"tiden" mode requires %s, which is not set', $configKey),
            sprintf('set %s (or %s in tiden.config.json)', $envName, $configKey),
        );
    }

    public static function missingReportPath(): self
    {
        return new self(
            '"report" mode requires report.connections.local.path, which is not set',
            'set TIDEN_REPORT_CONNECTION_PATH to a directory to write results into',
        );
    }

    public function message(): string
    {
        return sprintf(
            'reporter disabled — nothing will be reported to Tiden, because %s. To enable it, %s.',
            $this->reason,
            $this->remedy,
        );
    }
}
