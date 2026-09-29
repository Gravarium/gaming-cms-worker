<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\ExternalConnector\ExternalMediaObject;
use PHPUnit\Framework\TestCase;

final class ExternalMediaObjectTest extends TestCase
{
    public function testNormalizesOnlyLocationWhitespaceAndPreservesObjectIdentity(): void
    {
        $object = new ExternalMediaObject(
            'video/Example File.mp4',
            ' https://cdn.example.test/video/file.mp4 ',
            1234,
        );

        self::assertSame('video/Example File.mp4', $object->objectKey);
        self::assertSame('https://cdn.example.test/video/file.mp4', $object->location);
        self::assertSame(1234, $object->size);
    }

    public function testAllowsAnUnknownProviderSize(): void
    {
        $object = new ExternalMediaObject('video/file.mp4', 'https://cdn.example.test/video/file.mp4');

        self::assertNull($object->size);
    }

    public function testRejectsUnsafeObjectKeys(): void
    {
        $this->assertInvalid(static fn (): ExternalMediaObject => new ExternalMediaObject('', 'https://cdn.example.test/file'));
        $this->assertInvalid(static fn (): ExternalMediaObject => new ExternalMediaObject('/video/file.mp4', 'https://cdn.example.test/file'));
        $this->assertInvalid(static fn (): ExternalMediaObject => new ExternalMediaObject('video/../file.mp4', 'https://cdn.example.test/file'));
        $this->assertInvalid(static fn (): ExternalMediaObject => new ExternalMediaObject('video//file.mp4', 'https://cdn.example.test/file'));
        $this->assertInvalid(static fn (): ExternalMediaObject => new ExternalMediaObject("video/file\xFF.mp4", 'https://cdn.example.test/file'));
        $this->assertInvalid(static fn (): ExternalMediaObject => new ExternalMediaObject("video/file\n.mp4", 'https://cdn.example.test/file'));
        $this->assertInvalid(static fn (): ExternalMediaObject => new ExternalMediaObject(' video/file.mp4', 'https://cdn.example.test/file'));
    }

    public function testRejectsUnsafeLocations(): void
    {
        $this->assertInvalid(static fn (): ExternalMediaObject => new ExternalMediaObject('video/file.mp4', ''));
        $this->assertInvalid(static fn (): ExternalMediaObject => new ExternalMediaObject('video/file.mp4', str_repeat('a', 2049)));
        $this->assertInvalid(static fn (): ExternalMediaObject => new ExternalMediaObject('video/file.mp4', "https://cdn.example.test/\xFF"));
        $this->assertInvalid(static fn (): ExternalMediaObject => new ExternalMediaObject('video/file.mp4', "https://cdn.example.test/\x00"));
        $this->assertInvalid(static fn (): ExternalMediaObject => new ExternalMediaObject('video/file.mp4', 'https://cdn.example.test\\file'));
    }

    public function testRejectsInvalidSizes(): void
    {
        $this->assertInvalid(static fn (): ExternalMediaObject => new ExternalMediaObject('video/file.mp4', 'https://cdn.example.test/file', -1));
        $this->assertInvalid(static fn (): ExternalMediaObject => new ExternalMediaObject('video/file.mp4', 'https://cdn.example.test/file', 1_000_000_000_001));
    }

    private function assertInvalid(callable $factory): void
    {
        try {
            $factory();
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);

            return;
        }

        self::fail('The external media object input was accepted.');
    }
}
