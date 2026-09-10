<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Fixture;

use Tiden\PHPUnitReporter\Attribute\Field;
use Tiden\PHPUnitReporter\Attribute\Parameter;
use Tiden\PHPUnitReporter\Attribute\Suite;
use Tiden\PHPUnitReporter\Attribute\Tags;
use Tiden\PHPUnitReporter\Attribute\Title;

/**
 * Not a test class — a reflection target for AttributeReaderTest.
 */
#[Suite('Billing')]
#[Tags('regression')]
#[Field('layer', 'unit')]
class AttributedFixture
{
    #[Title('Charges the customer exactly once')]
    #[Suite('Invoices')]
    #[Tags('smoke', 'regression')]
    #[Field('owner', 'payments')]
    #[Parameter('currency', 'EUR')]
    public function testCharges(): void {}

    public function testPlain(): void {}

    #[Field('file_path', 'somewhere/else.php')]
    public function testForgesAFilePath(): void {}
}
