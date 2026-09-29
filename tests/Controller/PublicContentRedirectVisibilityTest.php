<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\ContentRedirect;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicContentRedirectVisibilityTest extends WebTestCase
{
    public function testHistoricalSlugsRedirectOnlyToCurrentlyPublishedContent(): void
    {
        $client = static::createClient();
        $entryIds = [];
        $redirectIds = [];
        $authorId = null;

        try {
            $em = $this->em($client);
            $author = (new User())
                ->setEmail('redirect-visibility-'.bin2hex(random_bytes(6)).'@example.test')
                ->setDisplayName('Redirect visibility')
                ->verifyEmail();
            $em->persist($author);
            $em->flush();

            $authorId = $author->getId();
            if ($authorId === null) {
                throw new \LogicException('The synthetic author was not persisted.');
            }

            $suffix = bin2hex(random_bytes(6));
            $future = new \DateTimeImmutable('+2 days');
            $publishedMarker = 'published-target-'.bin2hex(random_bytes(5));
            $draftMarker = 'draft-target-'.bin2hex(random_bytes(5));
            $scheduledNewsMarker = 'scheduled-news-target-'.bin2hex(random_bytes(5));
            $futureNewsMarker = 'future-news-target-'.bin2hex(random_bytes(5));
            $scheduledPageMarker = 'scheduled-page-target-'.bin2hex(random_bytes(5));
            $futurePageMarker = 'future-page-target-'.bin2hex(random_bytes(5));

            $published = $this->entry(
                $author,
                $publishedMarker,
                'current-news-'.$suffix,
                ContentEntry::TYPE_NEWS,
                ContentEntry::STATUS_PUBLISHED,
                new \DateTimeImmutable('-1 day'),
                null,
            );
            $draft = $this->entry(
                $author,
                $draftMarker,
                'target-draft-'.$suffix,
                ContentEntry::TYPE_NEWS,
                ContentEntry::STATUS_DRAFT,
                null,
                null,
            );
            $scheduledNews = $this->entry(
                $author,
                $scheduledNewsMarker,
                'target-scheduled-news-'.$suffix,
                ContentEntry::TYPE_NEWS,
                ContentEntry::STATUS_SCHEDULED,
                null,
                $future,
            );
            $futureNews = $this->entry(
                $author,
                $futureNewsMarker,
                'target-future-news-'.$suffix,
                ContentEntry::TYPE_NEWS,
                ContentEntry::STATUS_PUBLISHED,
                $future,
                null,
            );
            $scheduledPage = $this->entry(
                $author,
                $scheduledPageMarker,
                'target-scheduled-page-'.$suffix,
                ContentEntry::TYPE_PAGE,
                ContentEntry::STATUS_SCHEDULED,
                null,
                $future,
            );
            $futurePage = $this->entry(
                $author,
                $futurePageMarker,
                'target-future-page-'.$suffix,
                ContentEntry::TYPE_PAGE,
                ContentEntry::STATUS_PUBLISHED,
                $future,
                null,
            );

            /** @var list<ContentEntry> $entries */
            $entries = [$published, $draft, $scheduledNews, $futureNews, $scheduledPage, $futurePage];
            foreach ($entries as $entry) {
                $em->persist($entry);
            }

            $redirects = [
                new ContentRedirect($published, ContentEntry::TYPE_NEWS, 'legacy-published-'.$suffix),
                new ContentRedirect($draft, ContentEntry::TYPE_NEWS, 'legacy-draft-'.$suffix),
                new ContentRedirect($scheduledNews, ContentEntry::TYPE_NEWS, 'legacy-scheduled-news-'.$suffix),
                new ContentRedirect($futureNews, ContentEntry::TYPE_NEWS, 'legacy-future-news-'.$suffix),
                new ContentRedirect($scheduledPage, ContentEntry::TYPE_PAGE, 'legacy-scheduled-page-'.$suffix),
                new ContentRedirect($futurePage, ContentEntry::TYPE_PAGE, 'legacy-future-page-'.$suffix),
            ];
            foreach ($redirects as $redirect) {
                $em->persist($redirect);
            }
            $em->flush();

            foreach ($entries as $entry) {
                $id = $entry->getId();
                if ($id === null) {
                    throw new \LogicException('A synthetic content entry was not persisted.');
                }
                $entryIds[] = $id;
            }
            foreach ($redirects as $redirect) {
                $id = $redirect->getId();
                if ($id === null) {
                    throw new \LogicException('A synthetic content redirect was not persisted.');
                }
                $redirectIds[] = $id;
            }

            $client->request('GET', '/news/legacy-published-'.$suffix);
            self::assertResponseStatusCodeSame(301);
            self::assertSame('/news/'.$published->getSlug(), $client->getResponse()->headers->get('Location'));

            /** @var list<array{path: string, marker: string, target_slug: string}> $hiddenRoutes */
            $hiddenRoutes = [
                ['path' => '/news/legacy-draft-'.$suffix, 'marker' => $draftMarker, 'target_slug' => $draft->getSlug()],
                ['path' => '/news/legacy-scheduled-news-'.$suffix, 'marker' => $scheduledNewsMarker, 'target_slug' => $scheduledNews->getSlug()],
                ['path' => '/news/legacy-future-news-'.$suffix, 'marker' => $futureNewsMarker, 'target_slug' => $futureNews->getSlug()],
                ['path' => '/page/legacy-scheduled-page-'.$suffix, 'marker' => $scheduledPageMarker, 'target_slug' => $scheduledPage->getSlug()],
                ['path' => '/page/legacy-future-page-'.$suffix, 'marker' => $futurePageMarker, 'target_slug' => $futurePage->getSlug()],
            ];
            foreach ($hiddenRoutes as $route) {
                $client->request('GET', $route['path']);
                self::assertResponseStatusCodeSame(404);
                $body = (string) $client->getResponse()->getContent();
                self::assertStringNotContainsString($route['marker'], $body);
                self::assertStringNotContainsString($route['target_slug'], $body);
            }
        } finally {
            $cleanup = $this->em($client);
            $cleanup->clear();
            foreach ($redirectIds as $id) {
                $redirect = $cleanup->find(ContentRedirect::class, $id);
                if ($redirect instanceof ContentRedirect) {
                    $cleanup->remove($redirect);
                }
            }
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
