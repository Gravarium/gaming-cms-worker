<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Video;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoPublicationVisibilityTest extends WebTestCase
{
    public function testPublicLibraryAndDetailHideDisabledUnpublishedAndFutureVideos(): void
    {
        $client = static::createClient();
        $visible = $this->createVideo($client, 'visible', true, new DateTimeImmutable('-1 hour'));
        $disabled = $this->createVideo($client, 'disabled', false, new DateTimeImmutable('-1 hour'));
        $unpublished = $this->createVideo($client, 'unpublished', true, null);
        $future = $this->createVideo($client, 'future', true, new DateTimeImmutable('+1 day'));
        $this->entityManager($client)->flush();

        $client->request('GET', '/videos');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/videos/'.$visible->getSlug().'"]');
        foreach ([$disabled, $unpublished, $future] as $hidden) {
            self::assertSelectorNotExists('a[href="/videos/'.$hidden->getSlug().'"]');
        }

        foreach ([$disabled, $unpublished, $future] as $hidden) {
            $client->request('GET', '/videos/'.$hidden->getSlug());
            self::assertResponseStatusCodeSame(404);
        }

        $client->request('GET', '/videos/'.$visible->getSlug());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $visible->getTitle());
    }

    private function createVideo(
        KernelBrowser $client,
        string $label,
        bool $enabled,
        ?DateTimeImmutable $publishedAt,
    ): Video {
        $suffix = bin2hex(random_bytes(4));
        $video = (new Video())
            ->setTitle('Visibility '.$label.' '.$suffix)
            ->setSlug('video-visibility-'.$label.'-'.$suffix)
            ->setDescription('Video publication visibility regression')
            ->setSourceType(Video::SOURCE_YOUTUBE)
            ->setSourceUrl('https://www.youtube.com/watch?v=abcdefghijk')
            ->setEnabled($enabled)
            ->setPublishedAt($publishedAt);
        $this->entityManager($client)->persist($video);

        return $video;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
