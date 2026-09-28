<?php

declare(strict_types=1);

namespace App\\Tests\\Controller;

use App\\Entity\\CmsModuleState;
use App\\Entity\\Video;
use Doctrine\\ORM\\EntityManagerInterface;
use Symfony\\Bundle\\FrameworkBundle\\KernelBrowser;
use Symfony\\Bundle\\FrameworkBundle\\Test\\WebTestCase;

final class VideoDiscoveryEntryNavigationTest extends WebTestCase
{
    public function testVideoLibraryAndPublishedVideoDetailLinkToDiscoveryIndex(): void
    {
        $client = static::createClient();
        $slug = 'discovery-entry-'.bin2hex(random_bytes(5));
        $this->video($client, $slug);

        $crawler = $client->request('GET', '/videos/library');
        self::assertResponseIsSuccessful();
        $libraryLink = $crawler->filter('a[href="/video-discovery"]');
        self::assertCount(1, $libraryLink);
        self::assertSame('Video-Entdeckung', trim($libraryLink->text()));

        $client->click($libraryLink->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Video-Entdeckung');

        $crawler = $client->request('GET', '/videos/'.$slug);
        self::assertResponseIsSuccessful();
        $detailLink = $crawler->filter('a[href="/video-discovery"]');
        self::assertCount(1, $detailLink);
        self::assertSame('Video-Entdeckung', trim($detailLink->text()));

        $client->click($detailLink->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Video-Entdeckung');
    }

    public function testVideoDiscoveryEntryRemainsUnavailableWhenVideoModuleIsDisabled(): void
    {
        $client = static::createClient();
        $previous = $this->setVideoModuleEnabled($client, false);

        try {
            $client->request('GET', '/video-discovery');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreVideoModuleEnabled($client, $previous);
        }
    }

    private function video(KernelBrowser $client, string $slug): Video
    {
        $video = (new Video())
            ->setTitle('Discovery entry '.$slug)
            ->setSlug($slug)
            ->setDescription('Video used in the discovery entry test.')
            ->setSourceType(Video::SOURCE_YOUTUBE)
            ->setSourceUrl('https://www.youtube.com/watch?v=abcdefghijk')
            ->setPublishedAt(new \\DateTimeImmutable('-1 hour'))
            ->setEnabled(true);

        $this->entityManager($client)->persist($video);
        $this->entityManager($client)->flush();

        return $video;
    }

    private function setVideoModuleEnabled(KernelBrowser $client, bool $enabled): ?bool
    {
        $em = $this->entityManager($client);
        $state = $em->find(CmsModuleState::class, 'video');
        $previous = $state?->isEnabled();
        if ($state === null) {
            $state = (new CmsModuleState())->setModuleKey('video');
        }
        $state->setEnabled($enabled);
        $em->persist($state);
        $em->flush();

        return $previous;
    }

    private function restoreVideoModuleEnabled(KernelBrowser $client, ?bool $previous): void
    {
        $em = $this->entityManager($client);
        $state = $em->find(CmsModuleState::class, 'video');
        if ($state === null) {
            return;
        }
        if ($previous === null) {
            $em->remove($state);
        } else {
            $state->setEnabled($previous);
        }
        $em->flush();
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
