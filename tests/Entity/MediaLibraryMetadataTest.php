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

    public function testRejectsOversizedTagInputsWithoutMutatingExistingTags(): void
    {
        $asset = (new MediaAsset())->setTags(['kept']);

        $asset->setTagsText(str_repeat('x', 4097));
        self::assertSame(['kept'], $asset->getTags());

        $asset->setTagsText(implode(',', array_fill(0, 121, 'tag')));
        self::assertSame(['kept'], $asset->getTags());

        $asset->setTags(array_fill(0, 121, 'tag'));
        self::assertSame(['kept'], $asset->getTags());

        $asset->setTags(['primary' => 'tag']);
        self::assertSame(['kept'], $asset->getTags());
    }

    public function testAcceptsTagTextAtTheByteBoundaryAndKeepsExistingNormalization(): void
    {
        $segments = array_merge(
            [str_repeat(' ', 151).'x'],
            array_fill(0, 29, str_repeat(' ', 134).'x'),
        );
        $text = implode(',', $segments);
        self::assertSame(4096, strlen($text));

        $asset = (new MediaAsset())->setTagsText($text);
        self::assertSame(['x'], $asset->getTags());
    }

    public function testCapsCandidatesAndSkipsMalformedTagValues(): void
    {
        $asset = new MediaAsset();
        $candidates = array_merge(
            array_map(static fn (int $index): string => 'Tag '.$index, range(1, 30)),
            array_fill(0, 90, 'Tag 1'),
        );

        $asset->setTags($candidates);
        self::assertCount(30, $asset->getTags());
        self::assertSame('tag 1', $asset->getTags()[0]);
        self::assertSame('tag 30', $asset->getTags()[29]);

        $asset->setTags([
            ' Raid ',
            7,
            null,
            ['nested'],
            new \stdClass(),
            "\xFF",
            str_repeat('x', 1025),
            ' News ',
        ]);
        self::assertSame(['raid', 'news'], $asset->getTags());
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
