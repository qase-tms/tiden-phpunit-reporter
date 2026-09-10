<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Identity;

use Tiden\PHPUnitReporter\Core\Logging\Logger;

/**
 * Turns PHPUnit's absolute test file path into the repo-relative one Tiden
 * joins requirements on.
 *
 * fields["file_path"] is the join key for requirement<->test links: the server
 * matches a requirement's repo_file anchors against a test's file_path. A wrong
 * value silently makes a case unlinkable, so this never fabricates one.
 *
 * Policy, in two layers:
 *
 *  - A single file outside the root is OMITTED, with a warning naming it. That
 *    case is unlinkable but honest, and it matches tiden-cli's Go behaviour
 *    (unknown module => omit the field, never fail the report). Failing a whole
 *    run over one stray path would be worse than one unlinkable case.
 *
 *  - EVERY file outside the root is a misconfiguration, not an edge case. The
 *    concrete way this happens: the suite runs in a container that writes
 *    "/application/tests/..." while TIDEN_ROOT_DIR still points at the host
 *    checkout. Silently losing all links looks identical to success, so
 *    hasResolvedNone() is what the run lifecycle uses to refuse completion.
 */
final class FilePathResolver
{
    private readonly string $root;

    private int $resolved = 0;

    private int $omitted = 0;

    public function __construct(
        ?string $rootDir = null,
        private readonly ?Logger $logger = null,
    ) {
        $root = $rootDir ?? (getcwd() ?: '.');

        // realpath() resolves symlinks (/tmp -> /private/tmp on macOS, and any
        // symlinked checkout) so the prefix comparison below can succeed.
        $resolvedRoot = realpath($root);

        $this->root = rtrim(str_replace('\\', '/', $resolvedRoot !== false ? $resolvedRoot : $root), '/').'/';
    }

    /**
     * @return string|null Repo-relative, forward-slashed, no leading "./";
     *                     null when the file is not under the root.
     */
    public function relative(string $absolutePath): ?string
    {
        $normalized = str_replace('\\', '/', $absolutePath);

        $real = realpath($absolutePath);

        if ($real !== false) {
            $normalized = str_replace('\\', '/', $real);
        }

        if (! str_starts_with($normalized, $this->root)) {
            $this->omitted++;
            $this->logger?->warningOnce(
                'file_path:'.$normalized,
                sprintf(
                    'test file "%s" is not under the configured root "%s", so file_path is omitted and '.
                    'its case cannot be linked to a requirement. Set TIDEN_ROOT_DIR to the directory the '.
                    'test paths are relative to.',
                    $normalized,
                    rtrim($this->root, '/'),
                ),
            );

            return null;
        }

        $this->resolved++;

        return substr($normalized, strlen($this->root));
    }

    public function root(): string
    {
        return rtrim($this->root, '/');
    }

    public function resolvedCount(): int
    {
        return $this->resolved;
    }

    public function omittedCount(): int
    {
        return $this->omitted;
    }

    /**
     * True when at least one path was seen and none of them resolved — the
     * misconfiguration signal, distinct from "no tests ran at all".
     */
    public function hasResolvedNone(): bool
    {
        return $this->resolved === 0 && $this->omitted > 0;
    }
}
