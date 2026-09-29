<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Video;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicVideoPublicationBoundaryTest extends WebTestCase
{
    public function testPublicLibraryAndDetailRoutesExposeOnlyEnabledPublishedVideos(): void
    {
        $client = static::createClient();
        $fixture = $this->createVideos($client);

        try {
            $client->request('GET', '/videos');

            self::assertResponseIsSuccessful();
            $library = (string) $client->getResponse()->getContent();
            self::assertStringContainsString($fixture['visibleMarker'], $library);

            foreach ($fixture['hidden'] as $hidden) {
                self::assertStringNotContainsString($hidden['marker'], $library);

                $client->request('GET', '/videos/'.$hidden['slug']);

                self::assertResponseStatusCodeSame(404);
                self::assertStringNotContainsString($hidden['marker'], (string) $client->getResponse()->getContent());
            }

            $client->request('GET', '/videos/'.$fixture['visibleSlug']);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', $fixture['visibleMarker']);
        } finally {
            $this->cleanup($client, $fixture['ids']);
        }
    }

    /**
     * @return array{
     *     ids: list<int>,
     *     visibleSlug: string,
     *     visibleMarker: string,
     *     hidden: list<array{slug: string, marker: string}>
     * }
     */
    private function createVideos(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(6));
        $past = new \DateTimeImmutable('-1 day');
        $visibleSlug = 'public-video-'.$suffix;
        $visibleMarker = 'visible-video-body-'.$suffix;
        $unpublishedSlug = 'unpublished-video-'.$suffix;
        $unpublishedMarker = 'unpublished-video-body-'.$suffix;
        $futureSlug = 'future-video-'.$suffix;
        $futureMarker = 'future-video-body-'.$suffix;
        $disabledSlug = 'disabled-video-'.$suffix;
        $disabledMarker = 'disabled-video-body-'.$suffix;

        $visible = $this->video($visibleSlug, 'Visible video '.$suffix, $visibleMarker, true, $past);
        $unpublished = $this->video($unpublishedSlug, 'Unpublished video '.$suffix, $unpublishedMarker, true, null);
        $future = $this->video($futureSlug, 'Future video '.$suffix, $futureMarker, true, new \DateTimeImmutable('+1 day'));
        $disabled = $this->video($disabledSlug, 'Disabled video '.$suffix, $disabledMarker, false, $past);

        $videos = [$visible, $unpublished, $future, $disabled];
        $entityManager = $this->entityManager($client);
        foreach ($videos as $video) {
            $entityManager->persist($video);
        }
        $entityManager->flush();

        return [
            'ids' => [
                $this->requireId($visible->getId()),
                $this->requireId($unpublished->getId()),
                $this->requireId($future->getId()),
                $this->requireId($disabled->getId()),
            ],
            'visibleSlug' => $visibleSlug,
            'visibleMarker' => $visibleMarker,
            'hidden' => [
                ['slug' => $unpublishedSlug, 'marker' => $unpublishedMarker],
                ['slug' => $futureSlug, 'marker' => $futureMarker],
                ['slug' => $disabledSlug, 'marker' => $disabledMarker],
            ],
        ];
    }

    private function video(
        string $slug,
        string $title,
        string $description,
        bool $enabled,
        ?\DateTimeImmutable $publishedAt,
    ): Video {
        return (new Video())
            ->setTitle($title)
            ->setSlug($slug)
            ->setDescription($description)
            ->setSourceType(Video::SOURCE_YOUTUBE)
            ->setSourceUrl('https://youtu.be/abcdefghijk')
            ->setEnabled($enabled)
            ->setPublishedAt($publishedAt);
    }

    /** @param list<int> $ids */
    private function cleanup(KernelBrowser $client, array $ids): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        foreach ($ids as $id) {
            $video = $entityManager->find(Video::class, $id);
            if ($video instanceof Video) {
                $entityManager->remove($video);
            }
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    private function requireId(?int $id): int
    {
        if ($id === null) {
            throw new LogicException('Persisted video fixture has no identifier.');
        }

        return $id;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
