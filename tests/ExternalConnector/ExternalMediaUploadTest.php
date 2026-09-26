<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\ExternalConnector\ExternalMediaUpload;
use PHPUnit\Framework\TestCase;

final class ExternalMediaUploadTest extends TestCase
{
    public function testAcceptsSafeBoundedDescriptorAndPreservesMimeType(): void
    {
        $upload = new ExternalMediaUpload(
            'media/über/file.png',
            '/srv/cms/var/uploads/über/file.png',
            'image/png',
        );

        self::assertSame('media/über/file.png', $upload->objectKey);
        self::assertSame('/srv/cms/var/uploads/über/file.png', $upload->localPath);
        self::assertSame('image/png', $upload->mimeType);
    }

    public function testRejectsMalformedUtf8ObjectKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalMediaUpload("media/\xC3\x28.png", '/srv/cms/file.png');
    }

    public function testRejectsControlCharactersInObjectKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalMediaUpload("media/file\n.png", '/srv/cms/file.png');
    }

    public function testRejectsUnsafeObjectKeySegments(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalMediaUpload('media/../file.png', '/srv/cms/file.png');
    }

    public function testRejectsOversizedObjectKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalMediaUpload('media/'.str_repeat('a', 495), '/srv/cms/file.png');
    }

    public function testRejectsMalformedUtf8AndControlCharactersInLocalPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalMediaUpload('media/file.png', "/srv/cms/\xC3\x28\n.png");
    }

    public function testRejectsNonAbsoluteAndOversizedLocalPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExternalMediaUpload('media/file.png', 'relative/file.png');

        new ExternalMediaUpload('media/file.png', '/'.str_repeat('a', 500));
    }
}
