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
    /**
     * The other way to enable it: `tiden run exec` sets TIDEN_MODE, the token
     * and base URL from the CLI login, and the product from `--product-id` or
     * the repository's link. Named in full because an agent that read only a
     * variable name went looking for a token instead (AICP-135).
     */
    private const RUN_EXEC = 'run the suite as `tiden run exec -- <command>`';

    public const FROM_LOGIN = 'your tiden CLI login';

    public const FROM_REPOSITORY_LINK = 'this repository\'s Tiden link (`tiden setup`)';

    public function __construct(
        public readonly string $reason,
        public readonly string $remedy,
    ) {}

    public static function modeOff(): self
    {
        return new self(
            'mode is "off"',
            sprintf(
                'set TIDEN_MODE=tiden (or "report" to write results to disk), or %s, which sets it, '
                .'the credentials from %s and the product from %s',
                self::RUN_EXEC,
                self::FROM_LOGIN,
                self::FROM_REPOSITORY_LINK,
            ),
        );
    }

    /**
     * @param  string  $runExecSource  where `tiden run exec` takes this setting
     *                                 from: FROM_LOGIN or FROM_REPOSITORY_LINK
     */
    public static function missingSetting(string $configKey, string $envName, string $runExecSource): self
    {
        return new self(
            sprintf('"tiden" mode requires %s, which is not set', $configKey),
            sprintf(
                'set %s (or %s in tiden.config.json), or %s, which passes it from %s',
                $envName,
                $configKey,
                self::RUN_EXEC,
                $runExecSource,
            ),
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
