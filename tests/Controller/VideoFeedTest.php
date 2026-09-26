<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Video;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoFeedTest extends WebTestCase
{
    public function testFeedIsValidDiscoversFromVideoPageAndContainsOnlyPublishedVideos(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $now = new \DateTimeImmutable();
        $published = $this->video('published-'.$suffix, true, $now->modify('-1 hour'));
        $disabled = $this->video('disabled-'.$suffix, false, $now->modify('-30 minutes'));
        $draft = $this->video('draft-'.$suffix, true, null);
        $future = $this->video('future-'.$suffix, true, $now->modify('+1 day'));
        foreach ([$published, $disabled, $draft, $future] as $video) {
            $this->em($client)->persist($video);
        }
        $this->em($client)->flush();

        $crawler = $client->request('GET', '/videos/'.$published->getSlug());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('a[href="/feeds/videos.xml"]')->count());
        self::assertSame(1, $crawler->filter('link[rel="alternate"][type="application/rss+xml"]')->count());
        $alternateHref = $crawler->filter('link[rel="alternate"][type="application/rss+xml"]')->attr('href');
        self::assertNotNull($alternateHref);
        self::assertStringStartsWith('http://', $alternateHref);

        $client->request('GET', '/feeds/videos.xml');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/rss+xml', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('public', (string) $client->getResponse()->headers->get('Cache-Control'));

        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        $xml = new \DOMDocument();
        self::assertTrue($xml->loadXML($body));
        $items = $xml->getElementsByTagName('item');
        $titles = [];
        $links = [];
        foreach ($items as $item) {
            if (!$item instanceof \DOMElement) {
                continue;
            }
            $title = $item->getElementsByTagName('title')->item(0);
            $link = $item->getElementsByTagName('link')->item(0);
            if ($title instanceof \DOMElement && $link instanceof \DOMElement) {
                $titles[] = $title->textContent;
                $links[$title->textContent] = $link->textContent;
            }
        }

        self::assertContains($published->getTitle(), $titles);
        self::assertStringContainsString('RSS &amp; video ', $body);
        self::assertArrayHasKey($published->getTitle(), $links);
        self::assertStringStartsWith('http://', $links[$published->getTitle()]);
        self::assertStringEndsWith('/videos/'.$published->getSlug(), $links[$published->getTitle()]);
        self::assertNotContains($disabled->getTitle(), $titles);
        self::assertNotContains($draft->getTitle(), $titles);
        self::assertNotContains($future->getTitle(), $titles);
    }

    public function testFeedContainsAtMostFiftyVideos(): void
    {
        $client = static::createClient();
        $now = new \DateTimeImmutable();
        for ($index = 0; $index < 51; ++$index) {
            $video = $this->video(
                'bounded-'.bin2hex(random_bytes(4)).'-'.$index,
                true,
                $now->modify('-'.$index.' seconds'),
            );
            $this->em($client)->persist($video);
        }
        $this->em($client)->flush();

        $client->request('GET', '/feeds/videos.xml');

        self::assertResponseIsSuccessful();
        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        $xml = new \DOMDocument();
        self::assertTrue($xml->loadXML($body));
        self::assertSame(50, $xml->getElementsByTagName('item')->length);
    }

    public function testDisabledVideoModuleHidesTheFeedRoute(): void
    {
        $client = static::createClient();
        $user = $this->user($client);
        $em = $this->em($client);
        $existingState = $em->find(CmsModuleState::class, 'video');
        $previousEnabled = $existingState?->isEnabled();
        $state = $existingState ?? (new CmsModuleState())->setModuleKey('video')->updateVersion('1.0.0');
        $state->setEnabled(false);
        $em->persist($state);
        $em->flush();

        try {
            $client->loginUser($user);
            $client->request('GET', '/feeds/videos.xml');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $em = $this->em($client);
            $currentState = $em->find(CmsModuleState::class, 'video');
            if ($previousEnabled === null) {
                if ($currentState instanceof CmsModuleState) {
                    $em->remove($currentState);
                }
            } elseif ($currentState instanceof CmsModuleState) {
                $currentState->setEnabled($previousEnabled);
            }
            $em->flush();
        }
    }

    private function video(string $slug, bool $enabled, ?\DateTimeImmutable $publishedAt): Video
    {
        return (new Video())
            ->setTitle('RSS & video '.$slug)
            ->setSlug($slug)
            ->setDescription('Description & details for '.$slug)
            ->setSourceType(Video::SOURCE_YOUTUBE)
            ->setSourceUrl('https://www.youtube.com/watch?v=abcdefghijk')
            ->setEnabled($enabled)
            ->setPublishedAt($publishedAt);
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('video-feed-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Video feed test')
            ->setPermissions([CmsPermission::VIDEO])
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
