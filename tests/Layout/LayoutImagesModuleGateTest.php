<?php

declare(strict_types=1);

namespace App\Tests\Layout;

use App\Entity\CmsModuleState;
use App\Entity\MediaAsset;
use App\Layout\LayoutImages;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LayoutImagesModuleGateTest extends KernelTestCase
{
    public function testChoicesRespectMediaModuleAvailability(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $connection->beginTransaction();

        try {
            $state = $em->getRepository(CmsModuleState::class)->find('media');
            if (!$state instanceof CmsModuleState) {
                $state = (new CmsModuleState())->setModuleKey('media');
                $em->persist($state);
            }

            $state->install('1.0.0')->setEnabled(true);
            $asset = (new MediaAsset())
                ->setModuleKey('media')
                ->setMimeType('image/png')
                ->setLocation('/uploads/media/layout-module-gate-'.bin2hex(random_bytes(6)).'.png')
                ->setOriginalName('layout-module-gate.png')
                ->setTitle('Layout gate image');
            $em->persist($asset);
            $em->flush();

            $assetId = $asset->getId();
            self::assertNotNull($assetId);
            $images = self::getContainer()->get(LayoutImages::class);
            self::assertContains(['id' => $assetId, 'title' => 'Layout gate image'], $images->choices());

            $state->setEnabled(false);
            $em->flush();
            self::assertSame([], $images->choices());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $em->clear();
        }
    }
}
