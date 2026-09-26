<?php

declare(strict_types=1);
namespace App\Tests\Layout;

use App\ContentEditor\ContentBlockDocument;
use App\Entity\ContentEntry;
use App\Entity\MediaAsset;
use App\Entity\User;
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
        $asset=(new MediaAsset())->setMimeType('image/svg+xml')->setLocation('/uploads/media/unsafe.svg');self::assertFalse($images->usable($asset));
        $asset->setMimeType('image/png')->setLocation('javascript:alert(1)');self::assertFalse($images->usable($asset));
        $asset->setLocation('https://user:password@example.test/a.png');self::assertFalse($images->usable($asset));
        foreach (['/uploads/images/safe.png', '/uploads/media/../secret.png', '/uploads/media/evil\\\\file.png', "https://localhost/image.png", 'https://127.0.0.1/image.png', 'https://10.1.2.3/image.png', 'https://user:password@example.test/a.png'] as $url) {
            $asset->setLocation($url);self::assertFalse($images->usable($asset),$url);
        }
        $asset->setLocation('/uploads/media/safe.png');self::assertTrue($images->usable($asset));
        $asset->setLocation('https://cdn.example.test/image.png');self::assertTrue($images->usable($asset));
        $asset->setLocation('http://cdn.example.test/image.png');self::assertFalse($images->usable($asset));
        $asset->markDeletionPending();self::assertFalse($images->usable($asset));
    }
    public function testEditorMediaReferencesProtectAssetsAndIgnoreTextDecoys(): void
    {
        self::bootKernel();
        $container=self::getContainer();
        $em=$container->get(EntityManagerInterface::class);
        $usage=$container->get(MediaAssetUsageResolver::class);
        $blocks=$container->get(ContentBlockDocument::class);
        $suffix=bin2hex(random_bytes(6));
        $asset=(new MediaAsset())->setModuleKey('content')->setMimeType('image/png')->setLocation('/uploads/editor-reference-'.$suffix.'.png')->setOriginalName('reference.png');
        $decoy=(new MediaAsset())->setModuleKey('content')->setMimeType('image/png')->setLocation('/uploads/editor-decoy-'.$suffix.'.png')->setOriginalName('decoy.png');
        $author=(new User())->setEmail('wcp339-'.$suffix.'@example.test')->setDisplayName('WCP 339 test');
        $referencedEntry=null;
        $decoyEntry=null;

        try {
            $em->persist($asset);
            $em->persist($decoy);
            $em->persist($author);
            $em->flush();

            $assetId=$asset->getId();
            $decoyId=$decoy->getId();
            self::assertNotNull($assetId);
            self::assertNotNull($decoyId);
            $mediaDocument=$blocks->normalizeForStorage(
                ContentBlockDocument::PREFIX.'{"version":1,"blocks":[{"type":"media","assetId":'.$assetId.',"alt":"","caption":""}]}'
            );
            $decoyDocument=$blocks->normalizeForStorage(
                ContentBlockDocument::PREFIX.'{"version":1,"blocks":[{"type":"text","text":"The literal \"assetId\":'.$decoyId.', is not an actual media block."}]}'
            );
            $referencedEntry=(new ContentEntry())
                ->setType(ContentEntry::TYPE_PAGE)
                ->setTitle('Editor media reference')
                ->setSlug('wcp-339-reference-'.$suffix)
                ->setBody('Body without a media URL')
                ->setEditorDocument($mediaDocument)
                ->setAuthor($author);
            $decoyEntry=(new ContentEntry())
                ->setType(ContentEntry::TYPE_PAGE)
                ->setTitle('Editor text decoy')
                ->setSlug('wcp-339-decoy-'.$suffix)
                ->setBody('Body without a media URL')
                ->setEditorDocument($decoyDocument)
                ->setAuthor($author);
            $em->persist($referencedEntry);
            $em->persist($decoyEntry);
            $em->flush();

            self::assertContains('Seiten/News-Editor-Medien (1)',$usage->usages($asset));
            self::assertTrue($usage->isUsed($asset));
            self::assertNotContains('Seiten/News-Editor-Medien (1)',$usage->usages($decoy));
            self::assertFalse($usage->isUsed($decoy));
        } finally {
            if ($referencedEntry instanceof ContentEntry && $em->contains($referencedEntry)) { $em->remove($referencedEntry); }
            if ($decoyEntry instanceof ContentEntry && $em->contains($decoyEntry)) { $em->remove($decoyEntry); }
            foreach ([$author,$asset,$decoy] as $entity) {
                if ($em->contains($entity)) { $em->remove($entity); }
            }
            $em->flush();
        }
    }

    public function testOrphanedPageLayoutDoesNotBlockMediaDeletion(): void
    {
        self::bootKernel();$em=self::getContainer()->get(EntityManagerInterface::class);$usage=self::getContainer()->get(MediaAssetUsageResolver::class);
        $asset=(new MediaAsset())->setModuleKey('branding')->setMimeType('image/png')->setLocation('/uploads/media/orphan.png')->setOriginalName('orphan.png');
        $em->persist($asset);$em->flush();
        $layout=new PageLayout('page-999999999');
        try {
            $document=self::getContainer()->get(LayoutValidator::class)->defaults('nebula')->toArray();
            $document['widgets'][0]['config']['imageId']=$asset->getId();$layout->replace($document);$em->persist($layout);$em->flush();
            self::assertFalse($usage->isUsed($asset));
            self::assertSame(0,$usage->replaceUsages($asset,$asset));
        } finally { $em->remove($layout);$em->remove($asset);$em->flush(); }
    }

    public function testLayoutReferencesProtectMediaAndFollowSafeReplacement(): void
    {
        self::bootKernel();$em=self::getContainer()->get(EntityManagerInterface::class);$usage=self::getContainer()->get(MediaAssetUsageResolver::class);
        $old=(new MediaAsset())->setModuleKey('branding')->setMimeType('image/png')->setLocation('/uploads/layout-old.png')->setOriginalName('old.png');
        $new=(new MediaAsset())->setModuleKey('branding')->setMimeType('image/png')->setLocation('/uploads/layout-new.png')->setOriginalName('new.png');
        $em->persist($old);$em->persist($new);$em->flush();$layout=new PageLayout('home');
        try {
            $document=self::getContainer()->get(LayoutValidator::class)->defaults('nebula')->toArray();$document['widgets'][0]['config']['imageId']=$old->getId();$layout->replace($document);$em->persist($layout);$em->flush();
            self::assertTrue($usage->isUsed($old));self::assertSame(1,$usage->replaceUsages($old,$new));$em->flush();self::assertFalse($usage->isUsed($old));self::assertTrue($usage->isUsed($new));
            self::assertSame($new->getId(),$layout->getDocument()['widgets'][0]['config']['imageId']);
        } finally { $em->remove($layout);$em->remove($old);$em->remove($new);$em->flush(); }
    }
}
