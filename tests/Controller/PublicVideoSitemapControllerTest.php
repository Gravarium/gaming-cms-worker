<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Entity\Video;
use App\Security\CmsPermission;
use App\VideoSitemap\PublicVideoSitemapQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicVideoSitemapControllerTest extends WebTestCase
{
    public function testVideoSitemapContainsOnlyEnabledPublishedVideosAsAbsoluteXmlLocations(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(6));
        $now = new \DateTimeImmutable();
        $new = $this->video('sitemap-new-'.$suffix, true, $now->modify('-1 hour'));
        $old = $this->video('sitemap-old-'.$suffix, true, $now->modify('-2 days'));
        $disabled = $this->video('sitemap-disabled-'.$suffix, false, $now->modify('-30 minutes'));
        $unpublished = $this->video('sitemap-unpublished-'.$suffix, true, null);
        $future = $this->video('sitemap-future-'.$suffix, true, $now->modify('+1 day'));
        $em = $this->em($client);
        foreach ([$new, $old, $disabled, $unpublished, $future] as $video) {
            $em->persist($video);
        }
        $em->flush();

        try {
            $client->request('GET', '/sitemap-videos.xml');
            self::assertResponseIsSuccessful();
            self::assertStringStartsWith('application/xml', (string) $client->getResponse()->headers->get('Content-Type'));

            $document = new \DOMDocument();
            self::assertTrue($document->loadXML((string) $client->getResponse()->getContent(), LIBXML_NONET));
            $locations = $this->locations($document, 'urlset');
            $ourLocations = array_values(array_filter($locations, static fn (string $location): bool => str_contains($location, $suffix)));

            self::assertCount(2, $ourLocations);
            self::assertStringEndsWith('/videos/'.$new->getSlug(), $ourLocations[0]);
            self::assertStringEndsWith('/videos/'.$old->getSlug(), $ourLocations[1]);
            foreach ($ourLocations as $location) {
                self::assertSame('http', parse_url($location, PHP_URL_SCHEME));
                self::assertNotEmpty(parse_url($location, PHP_URL_HOST));
            }
            foreach ([$disabled, $unpublished, $future] as $excluded) {
                self::assertNotContains(
                    true,
                    array_map(static fn (string $location): bool => str_ends_with($location, '/videos/'.$excluded->getSlug()), $ourLocations),
                );
            }

            $etag = $client->getResponse()->headers->get('ETag');
            self::assertNotEmpty($etag);
            $client->request('GET', '/sitemap-videos.xml', [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(304);

            $client->request('POST', '/sitemap-videos.xml');
            self::assertResponseStatusCodeSame(405);
        } finally {
            $this->removeVideos($client, array_map(static fn (Video $video): ?int => $video->getId(), [$new, $old, $disabled, $unpublished, $future]));
        }
    }

    public function testSitemapIndexOnlyListsSitemapsForEnabledModules(): void
    {
        $client = static::createClient();
        $user = (new User())
            ->setEmail('video-sitemap-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Video sitemap test')
            ->setPermissions([CmsPermission::VIDEO])
            ->verifyEmail();
        $em = $this->em($client);
        $em->persist($user);
        $em->flush();
        $userId = $user->getId();

        $state = $em->getRepository(CmsModuleState::class)->find('video');
        $createdState = !$state instanceof CmsModuleState;
        $originalEnabled = $state?->isEnabled() ?? true;
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())
                ->setModuleKey('video')
                ->updateVersion('1.0.0');
        }
        $state->setEnabled(false);
        $em->persist($state);
        $em->flush();

        try {
            $client->loginUser($user);
            $client->request('GET', '/sitemap-videos.xml');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/sitemap-index.xml');
            self::assertResponseIsSuccessful();
            $document = new \DOMDocument();
            self::assertTrue($document->loadXML((string) $client->getResponse()->getContent(), LIBXML_NONET));
            $locations = $this->locations($document, 'sitemapindex');
            self::assertCount(1, $locations);
            self::assertStringEndsWith('/sitemap.xml', $locations[0]);
        } finally {
            $em = $this->em($client);
            $em->clear();
            $currentState = $em->getRepository(CmsModuleState::class)->find('video');
            if ($currentState instanceof CmsModuleState) {
                if ($createdState) {
                    $em->remove($currentState);
                } else {
                    $currentState->setEnabled($originalEnabled);
                }
            }
            if ($userId !== null) {
                $currentUser = $em->find(User::class, $userId);
                if ($currentUser instanceof User) {
                    $em->remove($currentUser);
                }
            }
            $em->flush();
        }
    }

    public function testSitemapUrlLimitIsClampedToProtocolBounds(): void
    {
        self::assertSame(1, PublicVideoSitemapQuery::boundedLimit(0));
        self::assertSame(137, PublicVideoSitemapQuery::boundedLimit(137));
        self::assertSame(PublicVideoSitemapQuery::MAX_URLS, PublicVideoSitemapQuery::boundedLimit(PHP_INT_MAX));
    }

    /**
     * @return list<string>
     */
    private function locations(\DOMDocument $document, string $root): array
    {
        self::assertSame($root, $document->documentElement?->localName);
        $nodes = $document->getElementsByTagNameNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'loc');
        $locations = [];
        foreach ($nodes as $node) {
            $locations[] = $node->textContent;
        }

        return $locations;
    }

    private function video(string $slug, bool $enabled, ?\DateTimeImmutable $publishedAt): Video
    {
        return (new Video())
            ->setTitle('Video sitemap '.$slug)
            ->setSlug($slug)
            ->setDescription('Video sitemap '.$slug)
            ->setSourceType(Video::SOURCE_YOUTUBE)
            ->setSourceUrl('https://www.youtube.com/watch?v=abcdefghijk')
            ->setEnabled($enabled)
            ->setPublishedAt($publishedAt);
    }

    /**
     * @param list<?int> $ids
     */
    private function removeVideos(KernelBrowser $client, array $ids): void
    {
        $em = $this->em($client);
        $em->clear();
        foreach ($ids as $id) {
            if ($id === null) {
                continue;
            }
            $video = $em->find(Video::class, $id);
            if ($video instanceof Video) {
                $em->remove($video);
            }
        }
        $em->flush();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
