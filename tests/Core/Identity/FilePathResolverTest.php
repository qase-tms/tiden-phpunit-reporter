<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core\Identity;

use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Identity\FilePathResolver;

final class FilePathResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/tiden-root-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/tests/Unit', 0o775, true);
        touch($this->root.'/tests/Unit/FooTest.php');
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/tests/Unit/FooTest.php');
        @rmdir($this->root.'/tests/Unit');
        @rmdir($this->root.'/tests');
        @rmdir($this->root);
    }

    public function test_produces_a_repo_relative_forward_slashed_path(): void
    {
        $resolver = new FilePathResolver($this->root);

        $this->assertSame('tests/Unit/FooTest.php', $resolver->relative($this->root.'/tests/Unit/FooTest.php'));
        $this->assertSame(1, $resolver->resolvedCount());
    }

    /**
     * The container case that motivates all of this: the suite runs with
     * /application mounted while the root still points at the host checkout.
     * The path is omitted rather than fabricated, because fields["file_path"]
     * is the requirement<->test join key and a wrong one links the case to the
     * wrong requirement.
     */
    public function test_omits_rather_than_fabricates_a_path_outside_the_root(): void
    {
        $resolver = new FilePathResolver($this->root);

        $this->assertNull($resolver->relative('/application/tests/Unit/FooTest.php'));
        $this->assertSame(0, $resolver->resolvedCount());
        $this->assertSame(1, $resolver->omittedCount());
    }

    /**
     * One stray path is an unlinkable case. EVERY path failing is a
     * misconfiguration that silently loses all traceability while looking like
     * a successful run, which is what hasResolvedNone() lets the run lifecycle
     * refuse to complete on.
     */
    public function test_distinguishes_one_stray_file_from_a_total_misconfiguration(): void
    {
        $partly = new FilePathResolver($this->root);
        $partly->relative($this->root.'/tests/Unit/FooTest.php');
        $partly->relative('/elsewhere/BarTest.php');

        $this->assertFalse($partly->hasResolvedNone());

        $entirely = new FilePathResolver($this->root);
        $entirely->relative('/application/tests/Unit/FooTest.php');

        $this->assertTrue($entirely->hasResolvedNone());
    }

    public function test_an_empty_run_is_not_a_misconfiguration(): void
    {
        $this->assertFalse((new FilePathResolver($this->root))->hasResolvedNone());
    }

    /**
     * macOS puts the temp directory behind a symlink (/tmp -> /private/tmp),
     * and symlinked checkouts are common. Comparing unresolved paths would make
     * every file look foreign.
     */
    public function test_resolves_symlinked_roots_and_files(): void
    {
        $link = sys_get_temp_dir().'/tiden-link-'.bin2hex(random_bytes(4));
        symlink($this->root, $link);

        try {
            $resolver = new FilePathResolver($link);

            $this->assertSame('tests/Unit/FooTest.php', $resolver->relative($link.'/tests/Unit/FooTest.php'));
        } finally {
            @unlink($link);
        }
    }

    public function test_defaults_to_the_working_directory(): void
    {
        $this->assertSame(getcwd(), (new FilePathResolver)->root());
    }

    public function test_a_trailing_slash_on_the_root_does_not_leak_into_the_path(): void
    {
        $resolver = new FilePathResolver($this->root.'/');

        $this->assertSame('tests/Unit/FooTest.php', $resolver->relative($this->root.'/tests/Unit/FooTest.php'));
    }
}
