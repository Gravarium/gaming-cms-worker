<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Video;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoAccessTest extends WebTestCase
{
    public function testVideoAdministrationRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/videos');
        self::assertResponseRedirects('/login');
    }

    public function testPublicVideoLibraryIsAvailable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/videos');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Videos');
    }

    public function testPublicVideoDetailEscapesStoredHtml(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $slug = 'escaping-video-'.$suffix;
        $titlePayload = '"><script>alert("video-'.$suffix.'")</script>';
        $descriptionPayload = 'A description <img src=x onerror="alert(1)"> end';
        $video = (new Video())
            ->setTitle($titlePayload)
            ->setSlug($slug)
            ->setDescription($descriptionPayload)
            ->setSourceType(Video::SOURCE_YOUTUBE)
            ->setSourceUrl('https://www.youtube.com/watch?v=abcdefghijk')
            ->setPublishedAt(new \DateTimeImmutable('-1 hour'));

        try {
            $entityManager->persist($video);
            $entityManager->flush();
            $crawler = $client->request('GET', '/videos/'.$slug);

            self::assertResponseIsSuccessful();
            $content = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($titlePayload, $content);
            self::assertStringNotContainsString($descriptionPayload, $content);
            self::assertStringContainsString(htmlspecialchars($titlePayload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $content);
            self::assertStringContainsString(htmlspecialchars($descriptionPayload, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $content);

            $main = $crawler->filter('main.video-page');
            self::assertSame(0, $main->filter('script, img[onerror]')->count());
            self::assertSame($titlePayload, $crawler->filter('h1')->text());
            self::assertSame(1, $crawler->filter('.video-player iframe')->count());
            self::assertSame($titlePayload, $crawler->filter('.video-player iframe')->attr('title'));
            self::assertSame($descriptionPayload, $crawler->filter('.article-body')->text('', true));
        } finally {
            $cleanup = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanup->isOpen()) {
                $storedVideo = $cleanup->getRepository(Video::class)->findOneBy(['slug' => $slug]);
                if ($storedVideo instanceof Video) {
                    $cleanup->remove($storedVideo);
                    $cleanup->flush();
                }
            }
        }
    }


}
