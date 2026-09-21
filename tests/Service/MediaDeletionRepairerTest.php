<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\MediaAsset;
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

            self::assertSame(0, $result['repaired']);
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
}
