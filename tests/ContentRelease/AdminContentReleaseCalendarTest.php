<?php

declare(strict_types=1);

namespace App\Tests\ContentRelease;

use App\ContentRelease\AdminContentReleaseCalendar;
use PHPUnit\Framework\TestCase;

final class AdminContentReleaseCalendarTest extends TestCase
{
    public function testMonthMustBeCanonicalAndWithinCalendarRange(): void
    {
        $calendar = (new \ReflectionClass(AdminContentReleaseCalendar::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(AdminContentReleaseCalendar::class, $calendar);

        foreach ([[], '0000-01', '9999-12', '2032-3', '2032-00', '2032-13', '2032-03-01', ' 2032-03'] as $month) {
            try {
                $calendar->read($month);
                self::fail('Expected invalid month to be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
