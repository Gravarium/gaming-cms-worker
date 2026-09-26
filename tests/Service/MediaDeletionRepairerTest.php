<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\ContentEditor\ContentBlockDocument;
use App\Entity\ContentEntry;
use App\Entity\MediaAsset;
use App\Entity\User;
use App\Entity\ModuleStorageSetting;
use App\Entity\Video;
use App\Service\MediaDeletionRepairer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MediaDeletionRepairerTest extends KernelTestCase
{
    public function testPendingAssetThatBecameUsedIsNotDeletedByRepair(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $asset = (new MediaAsset())
            ->setModuleKey('video')
            ->setStorageMode(ModuleStorageSetting::MODE_INTERNAL)
            ->setLocation('/uploads/media/video/repair-'.bin2hex(random_bytes(6)).'.mp4')
            ->setOriginalName('repair.mp4')
            ->setTitle('Repair')
            ->setMimeType('video/mp4')
            ->setFileSize(123);
        $video = (new Video())
            ->setTitle('Uses pending asset')
            ->setSlug('uses-pending-'.bin2hex(random_bytes(5)))
            ->setDescription('Regression test')
            ->setSourceType(Video::SOURCE_UPLOAD)
            ->setMediaAsset($asset);
        $asset->markDeletionPending();

        $em->persist($asset);
        $em->persist($video);
        $em->flush();
        $assetId = $asset->getId();
        $videoId = $video->getId();

        try {
            $result = $container->get(MediaDeletionRepairer::class)->repairPending();

            self::assertGreaterThanOrEqual(1, $result['failed']);
            $em->clear();
            self::assertInstanceOf(MediaAsset::class, $em->find(MediaAsset::class, $assetId));
        } finally {
            $em->clear();
            $storedVideo = $em->find(Video::class, $videoId);
            if ($storedVideo !== null) {
                $em->remove($storedVideo);
                $em->flush();
            }
            $storedAsset = $em->find(MediaAsset::class, $assetId);
            if ($storedAsset !== null) {
                $em->remove($storedAsset);
                $em->flush();
            }
        }
    }

    public function testEditorOnlyReferenceProtectsPendingAssetDuringRepair(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(6));
        $asset = (new MediaAsset())
            ->setModuleKey('content')
            ->setStorageMode(ModuleStorageSetting::MODE_INTERNAL)
            ->setLocation('/uploads/media/content/editor-repair-'.$suffix.'.png')
            ->setOriginalName('editor-repair.png')
            ->setTitle('Editor repair')
            ->setMimeType('image/png')
            ->setFileSize(123)
            ->markDeletionPending();
        $author = (new User())
            ->setEmail('wcp341-'.$suffix.'@example.test')
            ->setDisplayName('WCP 341 test');
        $assetId = null;
        $authorId = null;
        $entryId = null;

        try {
            $em->persist($asset);
            $em->persist($author);
            $em->flush();
            $assetId = $asset->getId();
            $authorId = $author->getId();
            self::assertNotNull($assetId);
            self::assertNotNull($authorId);

            $document = static::getContainer()->get(ContentBlockDocument::class)->normalizeForStorage(
                ContentBlockDocument::PREFIX.'{"version":1,"blocks":[{"type":"media","assetId":'.$assetId.',"alt":"","caption":""}]}'
            );
            $entry = (new ContentEntry())
                ->setType(ContentEntry::TYPE_PAGE)
                ->setTitle('Editor-only media reference')
                ->setSlug('wcp-341-editor-'.$suffix)
                ->setBody('No body URL references this asset')
                ->setEditorDocument($document)
                ->setAuthor($author);
            $em->persist($entry);
            $em->flush();
            $entryId = $entry->getId();
            self::assertNotNull($entryId);

            $result = $container->get(MediaDeletionRepairer::class)->repairPending();

            self::assertGreaterThanOrEqual(1, $result['failed']);
            $em->clear();
            self::assertInstanceOf(MediaAsset::class, $em->find(MediaAsset::class, $assetId));
        } finally {
            $em->clear();
            if ($entryId !== null) {
                $storedEntry = $em->find(ContentEntry::class, $entryId);
                if ($storedEntry !== null) {
                    $em->remove($storedEntry);
                    $em->flush();
                }
            }
            if ($assetId !== null) {
                $storedAsset = $em->find(MediaAsset::class, $assetId);
                if ($storedAsset !== null) {
                    $em->remove($storedAsset);
                    $em->flush();
                }
            }
            if ($authorId !== null) {
                $storedAuthor = $em->find(User::class, $authorId);
                if ($storedAuthor !== null) {
                    $em->remove($storedAuthor);
                    $em->flush();
                }
            }
        }
    }
}
