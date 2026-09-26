<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GuildAnnouncement;
use PHPUnit\Framework\TestCase;

final class GuildAnnouncementBoundaryTest extends TestCase
{
    public function testPreservesTrimAndAcceptsTheExactMappedTitleBoundary(): void
    {
        $asciiTitle = str_repeat('A', 180);
        $multibyteTitle = str_repeat('🎮', 180);

        self::assertSame($asciiTitle, (new GuildAnnouncement())->setTitle(' '.$asciiTitle.' ')->getTitle());

        $announcement = (new GuildAnnouncement())->setTitle($multibyteTitle);
        self::assertSame($multibyteTitle, $announcement->getTitle());
        self::assertSame(180, mb_strlen($announcement->getTitle(), 'UTF-8'));
        self::assertSame(720, strlen($announcement->getTitle()));

        self::assertSame('Guild update', (new GuildAnnouncement())->setTitle('  Guild update  ')->getTitle());
    }

    public function testRejectedTitlesLeaveTheStoredTitleUnchanged(): void
    {
        $announcement = (new GuildAnnouncement())->setTitle('Existing title');

        foreach ([str_repeat('a', 181), str_repeat('🎮', 181), "\xFFinvalid-utf8", "embedded\0nul"] as $title) {
            $this->assertRejected(static function () use ($announcement, $title): void {
                $announcement->setTitle($title);
            });

            self::assertSame('Existing title', $announcement->getTitle());
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

        self::fail('An invalid guild announcement title must be rejected.');
    }
}
