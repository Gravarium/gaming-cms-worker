<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SiteSettings;
use PHPUnit\Framework\TestCase;

final class SiteSettingsBoundaryTest extends TestCase
{
    public function testHomeTitleAcceptsExactAsciiAndMultibyteColumnBoundaries(): void
    {
        $asciiTitle = str_repeat('A', 180);
        self::assertSame($asciiTitle, (new SiteSettings())->setHomeTitle($asciiTitle)->getHomeTitle());

        $multibyteTitle = str_repeat('🎮', 180);
        self::assertSame(720, strlen($multibyteTitle));
        self::assertSame($multibyteTitle, (new SiteSettings())->setHomeTitle($multibyteTitle)->getHomeTitle());
    }

    public function testRejectedHomeTitlesDoNotReplaceTheStoredTitle(): void
    {
        $state = new SiteSettings();
        $originalTitle = $state->getHomeTitle();

        foreach ([str_repeat('a', 181), str_repeat('é', 181), str_repeat(' ', 721), "invalid\xFFutf8", "title\0suffix"] as $candidate) {
            $this->assertRejected(static function () use ($state, $candidate): void {
                $state->setHomeTitle($candidate);
            });

            self::assertSame($originalTitle, $state->getHomeTitle());
        }
    }

    public function testHomeTitleKeepsItsExistingTrimBehavior(): void
    {
        $state = new SiteSettings();

        self::assertSame('Welcome', $state->setHomeTitle('  Welcome  ')->getHomeTitle());
        self::assertSame('', $state->setHomeTitle('   ')->getHomeTitle());
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

        self::fail('An out-of-bound home title must be rejected.');
    }
}
