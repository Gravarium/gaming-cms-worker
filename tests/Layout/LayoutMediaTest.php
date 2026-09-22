<?php

declare(strict_types=1);
namespace App\Tests\Layout;

use App\Entity\MediaAsset;
use App\Entity\PageLayout;
use App\Layout\LayoutImages;
use App\Layout\LayoutValidator;
use App\Service\MediaAssetUsageResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LayoutMediaTest extends KernelTestCase
{
    public function testUnsafeMediaIsNotRenderable(): void
    {
        self::bootKernel();$images=self::getContainer()->get(LayoutImages::class);
        $asset=(new MediaAsset())->setMimeType('image/svg+xml')->setLocation('/uploads/unsafe.svg');self::assertFalse($images->usable($asset));
        $asset->setMimeType('image/png')->setLocation('javascript:alert(1)');self::assertFalse($images->usable($asset));
        $asset->setLocation('https://user:password@example.test/a.png');self::assertFalse($images->usable($asset));
        $asset->setLocation('/uploads/images/safe.png');self::assertTrue($images->usable($asset));
        $asset->markDeletionPending();self::assertFalse($images->usable($asset));
    }
    public function testLayoutReferencesProtectMediaAndFollowSafeReplacement(): void
    {
        self::bootKernel();$em=self::getContainer()->get(EntityManagerInterface::class);$usage=self::getContainer()->get(MediaAssetUsageResolver::class);
        $old=(new MediaAsset())->setModuleKey('branding')->setMimeType('image/png')->setLocation('/uploads/layout-old.png')->setOriginalName('old.png');
        $new=(new MediaAsset())->setModuleKey('branding')->setMimeType('image/png')->setLocation('/uploads/layout-new.png')->setOriginalName('new.png');
        $em->persist($old);$em->persist($new);$em->flush();$layout=new PageLayout('test-layout-media');
        try {
            $document=self::getContainer()->get(LayoutValidator::class)->defaults('nebula')->toArray();$document['widgets'][0]['config']['imageId']=$old->getId();$layout->replace($document);$em->persist($layout);$em->flush();
            self::assertTrue($usage->isUsed($old));self::assertSame(1,$usage->replaceUsages($old,$new));$em->flush();self::assertFalse($usage->isUsed($old));self::assertTrue($usage->isUsed($new));
            self::assertSame($new->getId(),$layout->getDocument()['widgets'][0]['config']['imageId']);
        } finally { $em->remove($layout);$em->remove($old);$em->remove($new);$em->flush(); }
    }
}
