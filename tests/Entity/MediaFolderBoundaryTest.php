<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MediaFolder;
use PHPUnit\Framework\TestCase;

final class MediaFolderBoundaryTest extends TestCase
{
    public function testPreservesTrimAndAcceptsTheExactMappedNameBoundary(): void
    {
        $asciiName = str_repeat('F', 120);
        $folder = (new MediaFolder())->setName(' '.$asciiName.' ');

        self::assertSame($asciiName, $folder->getName());

        $multibyteName = str_repeat('🎮', 120);
        $folder->setName($multibyteName);

        self::assertSame($multibyteName, $folder->getName());
        self::assertSame(120, mb_strlen($folder->getName(), 'UTF-8'));
        self::assertSame(480, strlen($folder->getName()));
    }

    public function testRejectedNamesLeaveTheStoredValueUnchanged(): void
    {
        $folder = (new MediaFolder())->setName('Existing folder');

        foreach ([str_repeat('x', 121), str_repeat('🎮', 121), "\xFFinvalid-utf8", "embedded\0nul"] as $name) {
            $this->assertRejected(static function () use ($folder, $name): void {
                $folder->setName($name);
            });

            self::assertSame('Existing folder', $folder->getName());
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

        self::fail('An invalid media folder name must be rejected.');
    }
}
