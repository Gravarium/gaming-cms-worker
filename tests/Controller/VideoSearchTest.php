<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Video;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoSearchTest extends WebTestCase
{
    public function testLibraryExposesSearchAndStaticRouteTakesPrecedenceOverVideoSlug(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/videos');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('form[role="search"][action="/videos/search"]')->count());
        self::assertSame(1, $crawler->filter('label[for="video-library-search"]')->count());
        self::assertSame('q', $crawler->filter('#video-library-search')->attr('name'));
        self::assertSame('2', $crawler->filter('#video-library-search')->attr('minlength'));
        self::assertSame('100', $crawler->filter('#video-library-search')->attr('maxlength'));

        $client->request('GET', '/videos/search');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Videos durchsuchen');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testSearchMatchesTitleAndDescriptionButOnlyEnabledCurrentlyPublishedVideos(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $term = 'needle'.$suffix;
        $now = new \DateTimeImmutable();

        $manager = $client->getContainer()->get(EntityManagerInterface::class);
        $videos = [
            $this->video(strtoupper($term).' title match '.$suffix, 'title-match-'.$suffix, 'Unrelated description', true, $now->modify('-1 hour')),
            $this->video('Description match '.$suffix, 'description-match-'.$suffix, 'Contains '.$term.' in the guide', true, $now->modify('-30 minutes')),
            $this->video('Disabled match '.$suffix, 'disabled-match-'.$suffix, 'Contains '.$term, false, $now->modify('-10 minutes')),
            $this->video('Draft match '.$suffix, 'draft-match-'.$suffix, 'Contains '.$term, true, null),
            $this->video('Future match '.$suffix, 'future-match-'.$suffix, 'Contains '.$term, true, $now->modify('+1 day')),
        ];
        foreach ($videos as $video) {
            $manager->persist($video);
        }
        $manager->flush();

        $client->request('GET', '/videos/search', ['q' => $term]);

        self::assertResponseIsSuccessful();
        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('title match '.$suffix, $body);
        self::assertStringContainsString('Description match '.$suffix, $body);
        self::assertStringNotContainsString('Disabled match '.$suffix, $body);
        self::assertStringNotContainsString('Draft match '.$suffix, $body);
        self::assertStringNotContainsString('Future match '.$suffix, $body);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testInvalidSearchValuesFailClosedWithoutEchoingInput(): void
    {
        $client = static::createClient();
        $long = 'overlong'.str_repeat('x', 101);

        foreach ([
            ['q' => 'x'],
            ['q' => $long],
            ['q' => ['malformed']],
        ] as $parameters) {
            $client->request('GET', '/videos/search', $parameters);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', 'Bitte gib einen Suchbegriff');
            $body = $client->getResponse()->getContent();
            self::assertIsString($body);
            self::assertStringNotContainsString('overlong', $body);
            self::assertStringNotContainsString('malformed', $body);
        }
    }

    public function testValidSearchWithoutMatchesShowsAnEmptyState(): void
    {
        $client = static::createClient();
        $client->request('GET', '/videos/search', ['q' => 'missing-'.bin2hex(random_bytes(5))]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Keine Videos gefunden');
    }

    private function video(string $title, string $slug, string $description, bool $enabled, ?\DateTimeImmutable $publishedAt): Video
    {
        return (new Video())
            ->setTitle($title)
            ->setSlug($slug)
            ->setDescription($description)
            ->setEnabled($enabled)
            ->setPublishedAt($publishedAt);
    }
}
