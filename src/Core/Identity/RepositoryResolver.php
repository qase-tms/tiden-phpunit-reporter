<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Core\Identity;

/**
 * Which repository the tests belong to, as the raw remote the server will
 * normalise ("https://github.com/acme/shop", "git@github.com:acme/shop.git").
 *
 * Order, first hit wins:
 *
 *  1. TIDEN_REPOSITORY (the explicit setting);
 *  2. the CI's own variables: GITHUB_SERVER_URL + GITHUB_REPOSITORY,
 *     CI_SERVER_URL + CI_PROJECT_PATH (GitLab), BITBUCKET_GIT_HTTP_ORIGIN,
 *     BUILD_REPOSITORY_URI (Azure Pipelines);
 *  3. the "origin" remote in .git/config of the nearest ancestor directory that
 *     has a ".git" (a directory, or the file a worktree or submodule has).
 *
 * The value is raw on purpose. The only thing done to it is removing a
 * user:password@ part from an http(s) URL, because a clone made with a token
 * keeps that token in .git/config and it must not travel to the API.
 *
 * No process is spawned: the file is read directly, so the lookup works where
 * the git binary is missing and costs nothing in a ParaTest worker.
 */
final class RepositoryResolver
{
    /** Every CI variable read here, so a caller can hand over a small env array. */
    public const CI_VARIABLES = [
        'GITHUB_SERVER_URL',
        'GITHUB_REPOSITORY',
        'CI_SERVER_URL',
        'CI_PROJECT_PATH',
        'BITBUCKET_GIT_HTTP_ORIGIN',
        'BUILD_REPOSITORY_URI',
    ];

    /** @return array<string, string> */
    public static function environment(): array
    {
        $env = [];

        foreach (self::CI_VARIABLES as $name) {
            $value = getenv($name);

            if (is_string($value)) {
                $env[$name] = $value;
            }
        }

        return $env;
    }

    /**
     * @param  array<string, string>  $env
     * @param  list<string|false>  $startDirs  Tried in order; the first one inside a git checkout with an origin wins.
     */
    public static function resolve(?string $explicit, array $env, array $startDirs): ?string
    {
        $found = self::clean($explicit)
            ?? self::fromCi($env)
            ?? self::fromGitConfig($startDirs);

        return $found === null ? null : self::withoutCredentials($found);
    }

    /** @param array<string, string> $env */
    private static function fromCi(array $env): ?string
    {
        $github = self::join($env['GITHUB_SERVER_URL'] ?? null, $env['GITHUB_REPOSITORY'] ?? null);
        $gitlab = self::join($env['CI_SERVER_URL'] ?? null, $env['CI_PROJECT_PATH'] ?? null);

        return $github
            ?? $gitlab
            ?? self::clean($env['BITBUCKET_GIT_HTTP_ORIGIN'] ?? null)
            ?? self::clean($env['BUILD_REPOSITORY_URI'] ?? null);
    }

    private static function join(?string $server, ?string $path): ?string
    {
        $server = self::clean($server);
        $path = self::clean($path);

        if ($server === null || $path === null) {
            return null;
        }

        return rtrim($server, '/').'/'.ltrim($path, '/');
    }

    /** @param list<string|false> $startDirs */
    private static function fromGitConfig(array $startDirs): ?string
    {
        foreach ($startDirs as $dir) {
            if ($dir === false || $dir === '') {
                continue;
            }

            $gitDir = self::findGitDir($dir);
            $origin = $gitDir === null ? null : self::originUrl($gitDir);

            if ($origin !== null) {
                return $origin;
            }
        }

        return null;
    }

    /** The git directory of the nearest ancestor with a ".git" entry, or null. */
    private static function findGitDir(string $start): ?string
    {
        $dir = realpath($start);

        if ($dir === false) {
            return null;
        }

        while (true) {
            $dotGit = $dir.'/.git';

            if (is_dir($dotGit)) {
                return $dotGit;
            }

            if (is_file($dotGit)) {
                // A worktree or a submodule: ".git" is a file, "gitdir: <path>".
                $line = trim((string) file_get_contents($dotGit));

                if (preg_match('/^gitdir:\s*(.+)$/m', $line, $m) !== 1) {
                    return null;
                }

                return self::absolute(trim($m[1]), $dir);
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                return null;
            }

            $dir = $parent;
        }
    }

    private static function originUrl(string $gitDir): ?string
    {
        // A linked worktree keeps its own gitdir but shares the repository's
        // config, found through "commondir". A submodule has no commondir: its
        // gitdir (under .git/modules) holds the config itself.
        $configDir = $gitDir;
        $commonDir = $gitDir.'/commondir';

        if (is_file($commonDir)) {
            $configDir = self::absolute(trim((string) file_get_contents($commonDir)), $gitDir);
        }

        $config = $configDir.'/config';

        if (! is_file($config)) {
            return null;
        }

        $inOrigin = false;

        foreach (file($config, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^\[\s*([^\s\]"]+)(?:\s+"(.*)")?\s*\]/', $line, $m) === 1) {
                $inOrigin = strtolower($m[1]) === 'remote' && ($m[2] ?? null) === 'origin';

                continue;
            }

            if ($inOrigin && preg_match('/^url\s*=\s*(.*)$/i', $line, $m) === 1) {
                return self::clean(trim($m[1], " \t\""));
            }
        }

        return null;
    }

    private static function absolute(string $path, string $base): string
    {
        $isAbsolute = str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;

        return $isAbsolute ? $path : $base.'/'.$path;
    }

    private static function withoutCredentials(string $remote): string
    {
        return preg_replace('#^(https?://)[^/@]*@#i', '$1', $remote) ?? $remote;
    }

    private static function clean(?string $value): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : $value;
    }
}
