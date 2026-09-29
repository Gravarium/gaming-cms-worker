<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicScheduledContentVisibilityTest extends WebTestCase
{
    public function testScheduledAndFutureDatedContentStaysHiddenFromPublicSurfaces(): void
    {
        $client = static::createClient();
        $entryIds = [];
        $authorId = null;

        try {
            $em = $this->em($client);
            $author = (new User())
                ->setEmail('scheduled-visibility-'.bin2hex(random_bytes(6)).'@example.test')
                ->setDisplayName('Scheduled visibility')
                ->verifyEmail();
            $em->persist($author);
            $em->flush();

            $authorId = $author->getId();
            if ($authorId === null) {
                throw new \LogicException('The synthetic author was not persisted.');
            }

            $suffix = bin2hex(random_bytes(6));
            $future = new \DateTimeImmutable('+2 days');
            $controlMarker = 'visible-control-'.$suffix;
            $futureNewsMarker = 'future-news-'.$suffix;
            $scheduledNewsMarker = 'scheduled-news-'.$suffix;
            $futurePageMarker = 'future-page-'.$suffix;
            $scheduledPageMarker = 'scheduled-page-'.$suffix;
            $futureNewsSlug = 'route-news-'.$suffix;
            $scheduledNewsSlug = 'route-scheduled-'.$suffix;
            $futurePageSlug = 'route-page-'.$suffix;
            $scheduledPageSlug = 'route-page-scheduled-'.$suffix;

            $control = $this->entry($author, $controlMarker, $controlMarker, ContentEntry::TYPE_NEWS, ContentEntry::STATUS_PUBLISHED, new \DateTimeImmutable(), null);
            $futureNews = $this->entry($author, $futureNewsMarker, $futureNewsSlug, ContentEntry::TYPE_NEWS, ContentEntry::STATUS_PUBLISHED, $future, null);
            $scheduledNews = $this->entry($author, $scheduledNewsMarker, $scheduledNewsSlug, ContentEntry::TYPE_NEWS, ContentEntry::STATUS_SCHEDULED, null, $future);
            $futurePage = $this->entry($author, $futurePageMarker, $futurePageSlug, ContentEntry::TYPE_PAGE, ContentEntry::STATUS_PUBLISHED, $future, null);
            $scheduledPage = $this->entry($author, $scheduledPageMarker, $scheduledPageSlug, ContentEntry::TYPE_PAGE, ContentEntry::STATUS_SCHEDULED, null, $future);

            /** @var list<ContentEntry> $entries */
            $entries = [$control, $futureNews, $scheduledNews, $futurePage, $scheduledPage];
            foreach ($entries as $entry) {
                $em->persist($entry);
            }
            $em->flush();

            foreach ($entries as $entry) {
                $id = $entry->getId();
                if ($id === null) {
                    throw new \LogicException('A synthetic content entry was not persisted.');
                }
                $entryIds[] = $id;
            }

            $hiddenMarkers = [
                $futureNewsMarker, $futureNewsSlug,
                $scheduledNewsMarker, $scheduledNewsSlug,
                $futurePageMarker, $futurePageSlug,
                $scheduledPageMarker, $scheduledPageSlug,
            ];

            $client->request('GET', '/news');
            self::assertResponseStatusCodeSame(200);
            $news = (string) $client->getResponse()->getContent();
            self::assertStringContainsString($controlMarker, $news);
            foreach ($hiddenMarkers as $marker) {
                self::assertStringNotContainsString($marker, $news);
            }

            /** @var list<array{path: string, marker: string}> $hiddenRoutes */
            $hiddenRoutes = [
                ['path' => '/news/'.$futureNews->getSlug(), 'marker' => $futureNewsMarker],
                ['path' => '/news/'.$scheduledNews->getSlug(), 'marker' => $scheduledNewsMarker],
                ['path' => '/page/'.$futurePage->getSlug(), 'marker' => $futurePageMarker],
                ['path' => '/page/'.$scheduledPage->getSlug(), 'marker' => $scheduledPageMarker],
            ];
            foreach ($hiddenRoutes as $route) {
                $client->request('GET', $route['path']);
                self::assertResponseStatusCodeSame(404);
                self::assertStringNotContainsString($route['marker'], (string) $client->getResponse()->getContent());
            }

            foreach (['/feeds/news.xml', '/feeds/news.json', '/sitemap.xml'] as $path) {
                $client->request('GET', $path);
                self::assertResponseStatusCodeSame(200);
                $body = (string) $client->getResponse()->getContent();
                self::assertStringContainsString($controlMarker, $body);
                foreach ($hiddenMarkers as $marker) {
                    self::assertStringNotContainsString($marker, $body);
                }
            }
        } finally {
            $cleanup = $this->em($client);
            $cleanup->clear();
            foreach ($entryIds as $id) {
                $entry = $cleanup->find(ContentEntry::class, $id);
                if ($entry instanceof ContentEntry) {
                    $cleanup->remove($entry);
                }
            }
            if ($authorId !== null) {
                $author = $cleanup->find(User::class, $authorId);
                if ($author instanceof User) {
                    $cleanup->remove($author);
                }
            }
            $cleanup->flush();
        }
    }

    private function entry(
        User $author,
        string $marker,
        string $slug,
        string $type,
        string $status,
        ?\DateTimeImmutable $publishedAt,
        ?\DateTimeImmutable $scheduledAt,
    ): ContentEntry {
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType($type)
            ->setTitle($marker)
            ->setSlug($slug)
            ->setExcerpt('Excerpt '.$marker)
            ->setBody('Body '.$marker)
            ->setStatus($status)
            ->setPublishedAt($publishedAt)
            ->setScheduledAt($scheduledAt);
        $entry->synchronizePublication();

        return $entry;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
