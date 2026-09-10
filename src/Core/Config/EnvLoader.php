<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Config;

/**
 * Maps TIDEN_* environment variables onto the nested config shape.
 *
 * Names are taken verbatim from tiden-javascript's
 * `commons/src/env/env-enum.ts` so that a repo already reporting from JS can
 * reuse the same variables for PHP. Two notes that are easy to get wrong:
 * the branch variable is TIDEN_BRANCH (not TIDEN_RUN_BRANCH), and the product
 * variable is TIDEN_PRODUCT_ID (not TIDEN_PRODUCT).
 *
 * TIDEN_BUILD_SHA and TIDEN_STATE_FILE have no commons counterpart yet;
 * buildSha exists on the wire (CreateTestRunBody.buildSha) and the state file
 * is a PHP-only need (ParaTest). Both are proposed additions to the shared
 * contract rather than PHP-private names.
 */
final class EnvLoader
{
    /**
     * Every variable this reporter reads.
     *
     * Also the accepted <parameter name="..."> set for the PHPUnit extension:
     * one vocabulary for a setting whether it comes from the environment,
     * tiden.config.json or phpunit.xml, rather than three spellings of the
     * same thing.
     *
     * @var list<string>
     */
    public const NAMES = [
        'TIDEN_MODE',
        'TIDEN_FALLBACK',
        'TIDEN_DEBUG',
        'TIDEN_ENVIRONMENT',
        'TIDEN_ROOT_SUITE',
        'TIDEN_ROOT_DIR',
        'TIDEN_STATUS_MAPPING',
        'TIDEN_STATE_FILE',
        'TIDEN_LOGGING_CONSOLE',
        'TIDEN_LOGGING_FILE',
        'TIDEN_PRODUCT_ID',
        'TIDEN_API_TOKEN',
        'TIDEN_BASE_URL',
        'TIDEN_RUN_ID',
        'TIDEN_RUN_TITLE',
        'TIDEN_RUN_DESCRIPTION',
        'TIDEN_RUN_COMPLETE',
        'TIDEN_BRANCH',
        'TIDEN_BUILD_SHA',
        'TIDEN_BATCH_SIZE',
        'TIDEN_REPORT_CONNECTION_PATH',
        'TIDEN_REPORT_CONNECTION_FORMAT',
    ];

    /**
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    public function load(array $env): array
    {
        $raw = [
            'mode' => Mode::tryFromString(self::str($env, 'TIDEN_MODE'))?->value,
            'fallback' => Mode::tryFromString(self::str($env, 'TIDEN_FALLBACK'))?->value,
            'debug' => self::bool($env, 'TIDEN_DEBUG'),
            'environment' => self::str($env, 'TIDEN_ENVIRONMENT'),
            'rootSuite' => self::str($env, 'TIDEN_ROOT_SUITE'),
            'rootDir' => self::str($env, 'TIDEN_ROOT_DIR'),
            'statusMapping' => self::mapping(self::str($env, 'TIDEN_STATUS_MAPPING')),
            'stateFile' => self::str($env, 'TIDEN_STATE_FILE'),
            'logging' => [
                'console' => self::bool($env, 'TIDEN_LOGGING_CONSOLE'),
                'file' => self::bool($env, 'TIDEN_LOGGING_FILE'),
            ],
            'tiden' => [
                'product' => self::str($env, 'TIDEN_PRODUCT_ID'),
                'api' => [
                    'token' => self::str($env, 'TIDEN_API_TOKEN'),
                    'baseUrl' => self::str($env, 'TIDEN_BASE_URL'),
                ],
                'run' => [
                    'id' => self::int($env, 'TIDEN_RUN_ID'),
                    'title' => self::str($env, 'TIDEN_RUN_TITLE'),
                    'description' => self::str($env, 'TIDEN_RUN_DESCRIPTION'),
                    'complete' => self::bool($env, 'TIDEN_RUN_COMPLETE'),
                    'branch' => self::str($env, 'TIDEN_BRANCH'),
                    'buildSha' => self::str($env, 'TIDEN_BUILD_SHA'),
                ],
                'batch' => [
                    'size' => self::int($env, 'TIDEN_BATCH_SIZE'),
                ],
            ],
            'report' => [
                'connections' => [
                    'local' => [
                        'path' => self::str($env, 'TIDEN_REPORT_CONNECTION_PATH'),
                        'format' => self::str($env, 'TIDEN_REPORT_CONNECTION_FORMAT'),
                    ],
                ],
            ],
        ];

        return ConfigMerge::prune($raw);
    }

    /**
     * Did the user express any intent to use Tiden at all? Governs whether a
     * disabled reporter announces itself or stays quiet.
     *
     * @param  array<string, string>  $env
     */
    public function hasAnyTidenVariable(array $env): bool
    {
        foreach (array_keys($env) as $name) {
            if (str_starts_with($name, 'TIDEN_')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $env */
    private static function str(array $env, string $name): ?string
    {
        $value = trim($env[$name] ?? '');

        return $value === '' ? null : $value;
    }

    /** @param array<string, string> $env */
    private static function int(array $env, string $name): ?int
    {
        $value = self::str($env, $name);

        if ($value === null || ! preg_match('/^-?\d+$/', $value)) {
            return null;
        }

        return (int) $value;
    }

    /** @param array<string, string> $env */
    private static function bool(array $env, string $name): ?bool
    {
        $value = self::str($env, $name);

        if ($value === null) {
            return null;
        }

        return match (strtolower($value)) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    /**
     * "failed=blocked,invalid=failed" -> ['failed' => 'blocked', 'invalid' => 'failed']
     *
     * @return array<string, string>|null
     */
    private static function mapping(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $mapping = [];

        foreach (explode(',', $value) as $pair) {
            $parts = explode('=', $pair, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $from = strtolower(trim($parts[0]));
            $to = strtolower(trim($parts[1]));

            if ($from !== '' && $to !== '') {
                $mapping[$from] = $to;
            }
        }

        return $mapping === [] ? null : $mapping;
    }
}
