<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Download\DownloadDependency;
use App\Entity\Download\DownloadMirror;
use App\Entity\Download\DownloadPackage;
use App\Entity\Download\DownloadVersion;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DownloadReleaseRelationsPublicTest extends WebTestCase
{
    public function testPublicReleaseShowsOnlyAuthorizedDependenciesAndTrustedMirrors(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(6));

        $source = new DownloadPackage('Public source '.$suffix, 'public-source-'.$suffix, 'mod');
        $visibleTarget = new DownloadPackage('Visible library '.$suffix, 'visible-library-'.$suffix, 'addon');
        $hiddenTarget = (new DownloadPackage('Secret component '.$suffix, 'secret-component-'.$suffix, 'addon'))
            ->setVisibility('admin');
        $version = (new DownloadVersion(
            $source,
            '1.0.0',
            'public-source.zip',
            hash('sha256', 'public-source-'.$suffix),
            '2026/09/public-source-'.$suffix.'.zip',
        ))->markScan(DownloadVersion::SCAN_CLEAN);

        $entityManager->persist($source);
        $entityManager->persist($visibleTarget);
        $entityManager->persist($hiddenTarget);
        $entityManager->persist($version);
        $entityManager->persist(new DownloadDependency($version, $visibleTarget, 'requires', '>=1.0.0'));
        $entityManager->persist(new DownloadDependency($version, $hiddenTarget, 'optional'));
        $entityManager->persist(new DownloadMirror($version, 'https://trusted.example.test/'.$suffix.'.zip', true));
        $entityManager->persist(new DownloadMirror($version, 'https://untrusted.example.test/'.$suffix.'.zip', false));
        $entityManager->flush();

        try {
            $client->request('GET', '/downloads/'.$source->getSlug());

            self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('Visible library '.$suffix, $content);
        self::assertSelectorTextContains('body', '>=1.0.0');
        self::assertStringNotContainsString('Secret component '.$suffix, $content);
        self::assertStringNotContainsString('secret-component-'.$suffix, $content);
        self::assertStringContainsString('https://trusted.example.test/'.$suffix.'.zip', $content);
        self::assertStringNotContainsString('untrusted.example.test', $content);
            self::assertSame('private, no-store', $client->getResponse()->headers->get('Cache-Control'));
            self::assertSelectorNotExists('a[href^="/admin/downloads/versions/"]');
        } finally {
            foreach ($entityManager->getRepository(DownloadDependency::class)->findBy(['version' => $version]) as $dependency) {
                $entityManager->remove($dependency);
            }
            foreach ($entityManager->getRepository(DownloadMirror::class)->findBy(['version' => $version]) as $mirror) {
                $entityManager->remove($mirror);
            }
            $entityManager->remove($version);
            $entityManager->remove($source);
            $entityManager->remove($visibleTarget);
            $entityManager->remove($hiddenTarget);
            $entityManager->flush();
        }
    }
}
