<?php

declare(strict_types=1);

namespace Tiden\PHPUnitReporter\Tests\Core;

use PHPUnit\Framework\TestCase;
use Tiden\PHPUnitReporter\Core\Uuid;

final class UuidTest extends TestCase
{
    /**
     * The result id is the API's idempotency key and IS VALIDATED as a UUID.
     * The Vitest reporter shipped a framework-native id here first; every
     * result was rejected with INVALID_RESULT_ID and the run sat at total=0
     * while appearing to have reported.
     */
    public function test_generates_rfc4122_version4(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $uuid = Uuid::v4();

            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $uuid,
            );
            $this->assertTrue(Uuid::isValid($uuid));
        }
    }

    public function test_ids_are_unique(): void
    {
        $ids = array_map(static fn (): string => Uuid::v4(), range(1, 1000));

        $this->assertCount(1000, array_unique($ids));
    }

    public function test_rejects_things_that_are_not_uuids(): void
    {
        foreach (['', 'FooTest::testBar', '1234', str_repeat('a', 36)] as $notAUuid) {
            $this->assertFalse(Uuid::isValid($notAUuid), $notAUuid);
        }
    }
}
