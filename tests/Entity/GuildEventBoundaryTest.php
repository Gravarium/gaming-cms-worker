<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GuildEvent;
use PHPUnit\Framework\TestCase;

final class GuildEventBoundaryTest extends TestCase
{
    public function testPreservesOptionalLocationNormalizationAtTheMappedBoundary(): void
    {
        $asciiLocation = str_repeat('L', 140);
        $event = (new GuildEvent())->setLocation(' '.$asciiLocation.' ');

        self::assertSame($asciiLocation, $event->getLocation());

        $multibyteLocation = str_repeat('🎮', 140);
        $event->setLocation($multibyteLocation);

        self::assertSame($multibyteLocation, $event->getLocation());
        self::assertSame(140, mb_strlen($event->getLocation() ?? '', 'UTF-8'));
        self::assertSame(560, strlen($event->getLocation() ?? ''));

        self::assertNull($event->setLocation('  ')->getLocation());
        self::assertNull($event->setLocation(null)->getLocation());
    }

    public function testRejectedLocationsLeaveTheStoredValueUnchanged(): void
    {
        $event = (new GuildEvent())->setLocation('Existing location');

        foreach ([str_repeat('x', 141), str_repeat('🎮', 141), "\xFFinvalid-utf8", "embedded\0nul"] as $location) {
            $this->assertRejected(static function () use ($event, $location): void {
                $event->setLocation($location);
            });

            self::assertSame('Existing location', $event->getLocation());
        }
    }

    /** @param \Closure(): mixed $operation */
    private function assertRejected(\Closure $operation): void
    {
        try {
            $operation();
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);

            return;
        }

        self::fail('An invalid guild event location must be rejected.');
    }
}
