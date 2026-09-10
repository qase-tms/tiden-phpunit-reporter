<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Attribute\AttributeReader;
use Tiden\PHPUnitReporter\Attribute\Metadata;
use Tiden\PHPUnitReporter\Tests\Fixture\AttributedFixture;

final class AttributeReaderTest extends TestCase
{
    public function test_method_title_wins(): void
    {
        $this->assertSame('Charges the customer exactly once', $this->read('testCharges')->title);
    }

    public function test_class_and_method_suites_accumulate_class_first(): void
    {
        $this->assertSame(['Billing', 'Invoices'], $this->read('testCharges')->suites);
    }

    public function test_tags_accumulate_and_deduplicate(): void
    {
        $this->assertSame(['regression', 'smoke'], $this->read('testCharges')->tags);
    }

    public function test_fields_merge_with_the_method_winning_on_a_clash(): void
    {
        $this->assertSame(['layer' => 'unit', 'owner' => 'payments'], $this->read('testCharges')->fields);
    }

    public function test_parameters_are_read_from_the_method(): void
    {
        $this->assertSame(['currency' => 'EUR'], $this->read('testCharges')->parameters);
    }

    /**
     * file_path is derived from the test's real file and is the
     * requirement<->test join key. Letting an attribute set it would let a test
     * claim to live somewhere it does not and link itself to the wrong
     * requirement.
     */
    public function test_a_field_cannot_forge_the_file_path_join_key(): void
    {
        $this->assertArrayNotHasKey('file_path', $this->read('testForgesAFilePath')->fields);
    }

    public function test_a_method_with_no_attributes_still_inherits_the_class_level_ones(): void
    {
        $metadata = $this->read('testPlain');

        $this->assertNull($metadata->title);
        $this->assertSame(['Billing'], $metadata->suites);
        $this->assertSame(['regression'], $metadata->tags);
    }

    /** A missing class must never take the run down; it degrades to no metadata. */
    public function test_an_unknown_class_yields_empty_metadata(): void
    {
        $metadata = (new AttributeReader)->read('No\Such\ClassAtAll', 'testAnything');

        $this->assertNull($metadata->title);
        $this->assertSame([], $metadata->suites);
    }

    public function test_an_unknown_method_on_a_known_class_still_reads_the_class_attributes(): void
    {
        $metadata = (new AttributeReader)->read(AttributedFixture::class, 'testNotDefined');

        $this->assertSame(['Billing'], $metadata->suites);
    }

    public function test_results_are_memoised(): void
    {
        $reader = new AttributeReader;

        $this->assertSame($reader->read(AttributedFixture::class, 'testCharges'), $reader->read(AttributedFixture::class, 'testCharges'));
    }

    private function read(string $method): Metadata
    {
        return (new AttributeReader)->read(AttributedFixture::class, $method);
    }
}
