<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Attribute;

use Attribute;

/**
 * Pins a test to existing Tiden cases by their sequence numbers, sent as the
 * result's testopsIds.
 *
 *   #[TidenId(42)]
 *   public function test_it_charges_once(): void
 *
 * This is the one attribute that does decide which case a test is. The server
 * matches by these ids before it looks at anything else, so it keeps working
 * when the test, its class or its namespace is renamed. Ids must be positive;
 * anything else is dropped with a warning.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class TidenId
{
    /** @var list<int> */
    public readonly array $ids;

    public function __construct(int ...$ids)
    {
        $this->ids = array_values($ids);
    }
}
