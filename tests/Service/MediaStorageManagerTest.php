<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MediaStorageManager;
use PHPUnit\Framework\TestCase;

final class MediaStorageManagerTest extends TestCase
{
    public function testLocalMediaPathRejectsMalformedUtf8BeforeConstructingPath(): void
    {
        $manager = (new \ReflectionClass(MediaStorageManager::class))->newInstanceWithoutConstructor();
        $projectDir = new \ReflectionProperty(MediaStorageManager::class, 'projectDir');
        $projectDir->setValue($manager, sys_get_temp_dir());
        $method = new \ReflectionMethod(MediaStorageManager::class, 'localMediaPath');

        $this->expectException(\DomainException::class);
        $method->invoke($manager, "/uploads/media/content/\xC3\x28.png");
    }

    public function testLocalMediaPathKeepsOwnedUploadNamespace(): void
    {
        $manager = (new \ReflectionClass(MediaStorageManager::class))->newInstanceWithoutConstructor();
        $projectDir = new \ReflectionProperty(MediaStorageManager::class, 'projectDir');
        $projectDir->setValue($manager, '/srv/cms');
        $method = new \ReflectionMethod(MediaStorageManager::class, 'localMediaPath');

        self::assertSame(
            '/srv/cms/public/uploads/media/content/file.png',
            $method->invoke($manager, '/uploads/media/content/file.png'),
        );
    }
}
