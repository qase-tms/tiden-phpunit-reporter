<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Config\EnvLoader;

final class EnvLoaderTest extends TestCase
{
    /**
     * EnvLoader::NAMES is also the accepted <parameter> set for phpunit.xml, so
     * a variable that is read but not listed is silently unsettable from
     * phpunit.xml, and one that is listed but never read is a documented lie.
     */
    public function test_the_declared_names_are_exactly_the_variables_actually_read(): void
    {
        preg_match_all("/'(TIDEN_[A-Z_]+)'/", (string) file_get_contents(__DIR__.'/../../../src/Core/Config/EnvLoader.php'), $matches);

        $referenced = array_values(array_unique($matches[1]));

        sort($referenced);
        $declared = EnvLoader::NAMES;
        sort($declared);

        $this->assertSame($declared, $referenced);
    }

    public function test_blank_and_whitespace_values_are_treated_as_unset(): void
    {
        $loaded = (new EnvLoader)->load(['TIDEN_ROOT_SUITE' => '   ', 'TIDEN_ENVIRONMENT' => '']);

        $this->assertSame([], $loaded);
    }

    public function test_unparseable_numbers_and_booleans_are_ignored_rather_than_coerced_to_zero(): void
    {
        // "maybe" silently becoming false, or "abc" becoming run 0, is worse
        // than the setting being absent.
        $loaded = (new EnvLoader)->load(['TIDEN_RUN_ID' => 'abc', 'TIDEN_DEBUG' => 'maybe']);

        $this->assertSame([], $loaded);
    }

    public function test_recognises_the_usual_boolean_spellings(): void
    {
        foreach (['1', 'true', 'TRUE', 'yes', 'on'] as $truthy) {
            $this->assertTrue((new EnvLoader)->load(['TIDEN_DEBUG' => $truthy])['debug'] ?? null, $truthy);
        }

        foreach (['0', 'false', 'no', 'off'] as $falsy) {
            $this->assertFalse((new EnvLoader)->load(['TIDEN_DEBUG' => $falsy])['debug'] ?? null, $falsy);
        }
    }

    public function test_detects_whether_the_user_expressed_any_intent(): void
    {
        $loader = new EnvLoader;

        $this->assertFalse($loader->hasAnyTidenVariable(['PATH' => '/usr/bin']));
        $this->assertTrue($loader->hasAnyTidenVariable(['TIDEN_MODE' => 'off']));
    }
}
