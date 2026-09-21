<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MediaAsset;
use App\Entity\MediaFolder;
use PHPUnit\Framework\TestCase;

final class MediaLibraryMetadataTest extends TestCase
{
    public function testMetadataIsNormalizedAndLimited(): void
    {
        $asset = (new MediaAsset())
            ->setOriginalName('Screenshot.PNG')
            ->setTitle('  Startbild  ')
            ->setCaption('  Beschreibung  ')
            ->setAltText('  Alternativtext  ')
            ->setTags([' Raid ', 'RAID', ' news ', '', str_repeat('x', 41)]);

        self::assertSame('Startbild', $asset->getTitle());
        self::assertSame('Beschreibung', $asset->getCaption());
        self::assertSame('Alternativtext', $asset->getAltText());
        self::assertSame(['raid', 'news'], $asset->getTags());
        self::assertSame('raid, news', $asset->getTagsText());
    }

    public function testEmptyTitleFallsBackToOriginalName(): void
    {
        $asset = (new MediaAsset())->setOriginalName('original.webp')->setTitle('');
        self::assertSame('original.webp', $asset->getTitle());
    }

    public function testFolderBuildsReadablePathAndRejectsItself(): void
    {
        $parent = (new MediaFolder())->setName('Bilder')->setSlug('bilder');
        $child = (new MediaFolder())->setName('News')->setSlug('news')->setParent($parent);
        self::assertSame('Bilder / News', $child->getPathLabel());

        $this->expectException(\DomainException::class);
        $child->setParent($child);
    }

    public function testFolderRejectsMovingAncestorBelowDescendant(): void
    {
        $root = (new MediaFolder())->setName('Root')->setSlug('root');
        $child = (new MediaFolder())->setName('Child')->setSlug('child')->setParent($root);
        $grandchild = (new MediaFolder())->setName('Grandchild')->setSlug('grandchild')->setParent($child);

        $this->expectException(\DomainException::class);
        $root->setParent($grandchild);
    }

    public function testMediaTypeHelpersRemainStable(): void
    {
        $asset = (new MediaAsset())->setMimeType('image/webp');
        self::assertTrue($asset->isImage());
        self::assertFalse($asset->isVideo());
    }
}
