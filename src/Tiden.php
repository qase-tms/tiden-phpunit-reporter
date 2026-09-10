<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter;

/**
 * The optional in-test API.
 *
 * Every method is a no-op when the extension was never bootstrapped, so a test
 * calling Tiden::comment() still runs unchanged for a developer who has the
 * package installed but no reporter configured.
 */
final class Tiden
{
    /** Override the reported title of the running test. */
    public static function title(string $title): void
    {
        Reporter::instance()?->setCurrentTitle($title);
    }

    /** Append a line to the running test's result message. */
    public static function comment(string $comment): void
    {
        Reporter::instance()?->addCurrentComment($comment);
    }
}
