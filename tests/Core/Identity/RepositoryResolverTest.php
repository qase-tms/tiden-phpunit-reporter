<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core\Identity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Identity\RepositoryResolver;

final class RepositoryResolverTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        // realpath: /tmp is a symlink on macOS and the resolver reports real paths.
        $this->dir = (string) realpath(sys_get_temp_dir()).'/tiden-repo-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        self::remove($this->dir);
    }

    /**
     * @param  array<string, string>  $env
     */
    #[DataProvider('ciEnvironments')]
    public function test_each_ci_provider_is_read(array $env, string $expected): void
    {
        $this->assertSame($expected, RepositoryResolver::resolve(null, $env, []));
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function ciEnvironments(): iterable
    {
        yield 'github actions' => [
            ['GITHUB_SERVER_URL' => 'https://github.com', 'GITHUB_REPOSITORY' => 'acme/shop'],
            'https://github.com/acme/shop',
        ];
        yield 'github enterprise, trailing slash' => [
            ['GITHUB_SERVER_URL' => 'https://git.acme.dev/', 'GITHUB_REPOSITORY' => 'acme/shop'],
            'https://git.acme.dev/acme/shop',
        ];
        yield 'gitlab ci, nested group' => [
            ['CI_SERVER_URL' => 'https://gitlab.com', 'CI_PROJECT_PATH' => 'acme/team/shop'],
            'https://gitlab.com/acme/team/shop',
        ];
        yield 'bitbucket pipelines' => [
            ['BITBUCKET_GIT_HTTP_ORIGIN' => 'http://bitbucket.org/acme/shop'],
            'http://bitbucket.org/acme/shop',
        ];
        yield 'azure pipelines' => [
            ['BUILD_REPOSITORY_URI' => 'https://dev.azure.com/acme/proj/_git/shop'],
            'https://dev.azure.com/acme/proj/_git/shop',
        ];
    }

    public function test_half_a_ci_pair_is_not_enough(): void
    {
        $this->assertNull(RepositoryResolver::resolve(null, ['GITHUB_SERVER_URL' => 'https://github.com'], []));
        $this->assertNull(RepositoryResolver::resolve(null, ['CI_PROJECT_PATH' => 'acme/shop'], []));
    }

    public function test_the_explicit_setting_beats_the_ci_and_the_checkout(): void
    {
        $this->checkout($this->dir, 'git@github.com:from/config.git');

        $found = RepositoryResolver::resolve(
            'https://github.com/explicit/repo',
            ['GITHUB_SERVER_URL' => 'https://github.com', 'GITHUB_REPOSITORY' => 'from/ci'],
            [$this->dir],
        );

        $this->assertSame('https://github.com/explicit/repo', $found);
    }

    public function test_the_ci_beats_the_checkout(): void
    {
        $this->checkout($this->dir, 'git@github.com:from/config.git');

        $found = RepositoryResolver::resolve(
            null,
            ['GITHUB_SERVER_URL' => 'https://github.com', 'GITHUB_REPOSITORY' => 'from/ci'],
            [$this->dir],
        );

        $this->assertSame('https://github.com/from/ci', $found);
    }

    public function test_the_origin_is_read_from_the_nearest_ancestor_with_a_git_directory(): void
    {
        $this->checkout($this->dir, 'git@github.com:acme/shop.git');
        mkdir($this->dir.'/tests/Unit', 0777, true);

        $this->assertSame(
            'git@github.com:acme/shop.git',
            RepositoryResolver::resolve(null, [], [$this->dir.'/tests/Unit']),
        );
    }

    public function test_only_the_origin_remote_counts(): void
    {
        $this->checkout($this->dir, 'git@github.com:acme/shop.git', other: 'https://example.com/fork/shop.git');

        $this->assertSame('git@github.com:acme/shop.git', RepositoryResolver::resolve(null, [], [$this->dir]));
    }

    public function test_a_checkout_with_no_origin_has_no_repository(): void
    {
        mkdir($this->dir.'/.git');
        file_put_contents($this->dir.'/.git/config', "[core]\n\tbare = false\n[remote \"upstream\"]\n\turl = https://example.com/a/b\n");

        $this->assertNull(RepositoryResolver::resolve(null, [], [$this->dir]));
    }

    /** A linked worktree: ".git" is a file and the config lives in the main repository. */
    public function test_a_worktree_git_file_is_followed_to_the_shared_config(): void
    {
        $main = $this->dir.'/main';
        $this->checkout($main, 'https://github.com/acme/shop.git');

        $worktreeGitDir = $main.'/.git/worktrees/feature';
        mkdir($worktreeGitDir, 0777, true);
        file_put_contents($worktreeGitDir.'/commondir', "../..\n");
        file_put_contents($worktreeGitDir.'/gitdir', $this->dir."/feature/.git\n");

        mkdir($this->dir.'/feature/src', 0777, true);
        file_put_contents($this->dir.'/feature/.git', 'gitdir: '.$worktreeGitDir."\n");

        $this->assertSame(
            'https://github.com/acme/shop.git',
            RepositoryResolver::resolve(null, [], [$this->dir.'/feature/src']),
        );
    }

    public function test_a_relative_gitdir_is_resolved_against_the_directory_holding_the_file(): void
    {
        $main = $this->dir.'/main';
        $this->checkout($main, 'https://github.com/acme/shop.git');

        $gitDir = $main.'/.git/worktrees/wt';
        mkdir($gitDir, 0777, true);
        file_put_contents($gitDir.'/commondir', "../..\n");

        mkdir($this->dir.'/wt');
        file_put_contents($this->dir.'/wt/.git', "gitdir: ../main/.git/worktrees/wt\n");

        $this->assertSame('https://github.com/acme/shop.git', RepositoryResolver::resolve(null, [], [$this->dir.'/wt']));
    }

    /** A submodule's gitdir has no commondir: its own config is the one. */
    public function test_a_submodule_git_file_is_followed_to_its_own_config(): void
    {
        $modules = $this->dir.'/super/.git/modules/lib';
        mkdir($modules, 0777, true);
        file_put_contents($modules.'/config', "[remote \"origin\"]\n\turl = git@github.com:acme/lib.git\n");

        mkdir($this->dir.'/super/lib');
        file_put_contents($this->dir.'/super/lib/.git', "gitdir: ../.git/modules/lib\n");

        $this->assertSame('git@github.com:acme/lib.git', RepositoryResolver::resolve(null, [], [$this->dir.'/super/lib']));
    }

    public function test_the_next_start_directory_is_tried_when_the_first_has_no_checkout(): void
    {
        $this->checkout($this->dir.'/repo', 'https://github.com/acme/shop.git');
        $this->assertSame(
            'https://github.com/acme/shop.git',
            RepositoryResolver::resolve(null, [], [$this->dir.'/missing', false, $this->dir.'/repo']),
        );
    }

    public function test_no_source_means_no_repository(): void
    {
        $this->assertNull(RepositoryResolver::resolve(null, [], [$this->dir.'/missing']));
        $this->assertNull(RepositoryResolver::resolve('  ', ['GITHUB_REPOSITORY' => ' '], []));
    }

    /** A clone made with a token keeps it in .git/config; it must not reach the API. */
    #[DataProvider('remotesWithCredentials')]
    public function test_credentials_in_a_url_are_not_sent(string $raw, string $expected): void
    {
        $this->checkout($this->dir, $raw);

        $this->assertSame($expected, RepositoryResolver::resolve(null, [], [$this->dir]));
        $this->assertSame($expected, RepositoryResolver::resolve($raw, [], []));
    }

    /** @return iterable<string, array{string, string}> */
    public static function remotesWithCredentials(): iterable
    {
        yield 'token as user' => ['https://ghs_secret@github.com/acme/shop.git', 'https://github.com/acme/shop.git'];
        yield 'user and password' => ['https://x-access-token:ghs_secret@github.com/acme/shop', 'https://github.com/acme/shop'];
        yield 'ssh keeps its git user' => ['git@github.com:acme/shop.git', 'git@github.com:acme/shop.git'];
        yield 'ssh url keeps its git user' => ['ssh://git@github.com/acme/shop.git', 'ssh://git@github.com/acme/shop.git'];
    }

    private const COMMIT = '3f786850e387550fdab836ed7e6dc881de23001b';

    private const OTHER = '89e6c98d92887913cadf06b2adb97f26cde4849b';

    public function test_head_on_a_branch_is_read_from_the_loose_ref(): void
    {
        $this->head($this->dir, "ref: refs/heads/main\n", ['refs/heads/main' => self::COMMIT."\n"]);
        mkdir($this->dir.'/tests/Unit', 0777, true);

        $this->assertSame(self::COMMIT, RepositoryResolver::headCommit([$this->dir.'/tests/Unit']));
    }

    public function test_head_on_a_branch_is_read_from_packed_refs_when_there_is_no_loose_ref(): void
    {
        $this->head($this->dir, "ref: refs/heads/main\n");
        file_put_contents($this->dir.'/.git/packed-refs', implode("\n", [
            '# pack-refs with: peeled fully-peeled sorted ',
            self::OTHER.' refs/heads/feature',
            self::COMMIT.' refs/heads/main',
            self::OTHER.' refs/tags/v1.0.0',
            '^'.self::COMMIT,
            '',
        ]));

        $this->assertSame(self::COMMIT, RepositoryResolver::headCommit([$this->dir]));
    }

    /** git writes a loose ref when a packed branch moves, so the loose one is the current one. */
    public function test_a_loose_ref_beats_the_packed_one(): void
    {
        $this->head($this->dir, "ref: refs/heads/main\n", ['refs/heads/main' => self::COMMIT."\n"]);
        file_put_contents($this->dir.'/.git/packed-refs', self::OTHER." refs/heads/main\n");

        $this->assertSame(self::COMMIT, RepositoryResolver::headCommit([$this->dir]));
    }

    /** CI checkouts of a tag or a pull request are usually detached: HEAD is the commit. */
    public function test_a_detached_head_is_the_commit_itself(): void
    {
        $this->head($this->dir, strtoupper(self::COMMIT)."\n");

        $this->assertSame(self::COMMIT, RepositoryResolver::headCommit([$this->dir]));
    }

    public function test_a_sha256_object_id_is_accepted(): void
    {
        $sha256 = str_repeat('ab12', 16);
        $this->head($this->dir, $sha256."\n");

        $this->assertSame($sha256, RepositoryResolver::headCommit([$this->dir]));
    }

    /** A linked worktree has its own HEAD but its branches live in the main repository. */
    public function test_a_worktree_head_is_resolved_through_the_shared_refs(): void
    {
        $main = $this->dir.'/main';
        $this->head($main, "ref: refs/heads/main\n", ['refs/heads/main' => self::OTHER."\n"]);
        file_put_contents($main.'/.git/packed-refs', self::COMMIT." refs/heads/feature\n");

        $worktreeGitDir = $main.'/.git/worktrees/feature';
        mkdir($worktreeGitDir, 0777, true);
        file_put_contents($worktreeGitDir.'/commondir', "../..\n");
        file_put_contents($worktreeGitDir.'/HEAD', "ref: refs/heads/feature\n");

        mkdir($this->dir.'/feature/src', 0777, true);
        file_put_contents($this->dir.'/feature/.git', 'gitdir: '.$worktreeGitDir."\n");

        $this->assertSame(self::COMMIT, RepositoryResolver::headCommit([$this->dir.'/feature/src']));
        $this->assertSame(self::OTHER, RepositoryResolver::headCommit([$main]), 'the main checkout keeps its own HEAD');
    }

    /** A submodule is usually checked out detached, through a relative gitdir. */
    public function test_a_submodule_head_is_read_through_its_relative_gitdir(): void
    {
        $modules = $this->dir.'/super/.git/modules/lib';
        mkdir($modules, 0777, true);
        file_put_contents($modules.'/HEAD', self::COMMIT."\n");

        mkdir($this->dir.'/super/lib');
        file_put_contents($this->dir.'/super/lib/.git', "gitdir: ../.git/modules/lib\n");

        $this->assertSame(self::COMMIT, RepositoryResolver::headCommit([$this->dir.'/super/lib']));
    }

    /** A symbolic ref may name another ref; it is followed to the commit. */
    public function test_a_chain_of_symbolic_refs_is_followed(): void
    {
        $this->head($this->dir, "ref: refs/heads/alias\n", [
            'refs/heads/alias' => "ref: refs/heads/main\n",
            'refs/heads/main' => self::COMMIT."\n",
        ]);

        $this->assertSame(self::COMMIT, RepositoryResolver::headCommit([$this->dir]));
    }

    /** "git init" with no commit yet: HEAD names a branch that does not exist. */
    public function test_an_unborn_branch_has_no_commit(): void
    {
        $this->head($this->dir, "ref: refs/heads/main\n");

        $this->assertNull(RepositoryResolver::headCommit([$this->dir]));
    }

    #[DataProvider('unreadableHeads')]
    public function test_a_head_that_is_not_a_commit_or_a_ref_gives_nothing(string $head): void
    {
        file_put_contents($this->dir.'/outside', self::COMMIT."\n");
        $this->head($this->dir, $head);

        $this->assertNull(RepositoryResolver::headCommit([$this->dir]));
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableHeads(): iterable
    {
        yield 'abbreviated id' => [substr(self::COMMIT, 0, 12)."\n"];
        yield 'not hex' => [str_repeat('z', 40)."\n"];
        yield 'empty' => [''];
        yield 'reftable placeholder' => ["ref: refs/heads/.invalid\n"];
        yield 'ref outside refs/' => ["ref: ../outside\n"];
        yield 'ref climbing out of refs/' => ["ref: refs/../../outside\n"];
    }

    public function test_the_next_start_directory_is_tried_when_the_first_has_no_head(): void
    {
        $this->head($this->dir.'/unborn', "ref: refs/heads/main\n");
        $this->head($this->dir.'/repo', self::COMMIT."\n");

        $this->assertSame(
            self::COMMIT,
            RepositoryResolver::headCommit([$this->dir.'/missing', false, '', $this->dir.'/unborn', $this->dir.'/repo']),
        );
    }

    public function test_no_checkout_means_no_commit(): void
    {
        $this->assertNull(RepositoryResolver::headCommit([$this->dir]));
        $this->assertNull(RepositoryResolver::headCommit([]));
    }

    /** @param array<string, string> $refs Ref name => file contents, written under the git directory. */
    private function head(string $root, string $head, array $refs = []): void
    {
        mkdir($root.'/.git', 0777, true);
        file_put_contents($root.'/.git/HEAD', $head);

        foreach ($refs as $name => $contents) {
            $file = $root.'/.git/'.$name;

            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }

            file_put_contents($file, $contents);
        }
    }

    private function checkout(string $root, string $origin, ?string $other = null): void
    {
        mkdir($root.'/.git', 0777, true);

        $config = "[core]\n\trepositoryformatversion = 0\n";

        if ($other !== null) {
            $config .= "[remote \"fork\"]\n\turl = {$other}\n";
        }

        $config .= "[remote \"origin\"]\n\turl = {$origin}\n\tfetch = +refs/heads/*:refs/remotes/origin/*\n[branch \"main\"]\n\tremote = origin\n";

        file_put_contents($root.'/.git/config', $config);
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }
}
