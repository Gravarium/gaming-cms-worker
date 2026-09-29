<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PublicNewsArchiveTest extends WebTestCase
{
    private const ARCHIVE_YEAR = 1985;
    private const ARCHIVE_MONTH = 5;

    public function testArchiveListsPeriodsAndPaginatesEntriesInStableOrder(): void
    {
        $client = static::createClient();
        $entryIds = [];
        $authorId = null;

        try {
            $author = $this->author($client);
            $authorId = $author->getId();
            $start = new \DateTimeImmutable('1985-05-01 09:00:00');

            for ($number = 1; $number <= 21; ++$number) {
                $entry = $this->entry(
                    $client,
                    $author,
                    sprintf('Archive marker %02d', $number),
                    ContentEntry::TYPE_NEWS,
                    ContentEntry::STATUS_PUBLISHED,
                    $start->modify(sprintf('+%d hours', $number)),
                );
                $entryIds[] = $entry->getId();
            }

            $client->request('GET', '/news/archive');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', '05/1985');
            self::assertSelectorExists('a[href="/news/archive/1985/05"]');

            $client->request('GET', '/news/archive/1985/05');
            self::assertResponseIsSuccessful();
            $titles = $this->archiveTitles($client);
            self::assertCount(20, $titles);
            self::assertSame('Archive marker 21', $titles[0]);
            self::assertSame('Archive marker 02', $titles[19]);
            self::assertSelectorTextContains('body', 'Seite 1 von 2');

            $client->request('GET', '/news/archive/1985/05?page=2');
            self::assertResponseIsSuccessful();
            $titles = $this->archiveTitles($client);
            self::assertCount(1, $titles);
            self::assertSame('Archive marker 01', $titles[0]);

            $client->request('GET', '/news/archive/1985/05?page=3');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, $entryIds, $authorId);
        }
    }

    public function testArchiveExcludesNonPublicAndExpiredContent(): void
    {
        $client = static::createClient();
        $entryIds = [];
        $authorId = null;

        try {
            $author = $this->author($client);
            $authorId = $author->getId();
            $visibleDate = new \DateTimeImmutable('1985-05-12 11:00:00');
            $now = new \DateTimeImmutable();

            $visible = $this->entry(
                $client,
                $author,
                'Visible archive marker',
                ContentEntry::TYPE_NEWS,
                ContentEntry::STATUS_PUBLISHED,
                $visibleDate,
            );
            $entryIds[] = $visible->getId();

            $hidden = [
                $this->entry($client, $author, 'Draft archive marker', ContentEntry::TYPE_NEWS, ContentEntry::STATUS_DRAFT, $visibleDate),
                $this->entry($client, $author, 'Scheduled archive marker', ContentEntry::TYPE_NEWS, ContentEntry::STATUS_SCHEDULED, null, false, null, $now->modify('+1 day')),
                $this->entry($client, $author, 'Future archive marker', ContentEntry::TYPE_NEWS, ContentEntry::STATUS_PUBLISHED, $now->modify('+1 day')),
                $this->entry($client, $author, 'Archived archive marker', ContentEntry::TYPE_NEWS, ContentEntry::STATUS_ARCHIVED, $visibleDate),
                $this->entry($client, $author, 'Trashed archive marker', ContentEntry::TYPE_NEWS, ContentEntry::STATUS_TRASHED, $visibleDate),
                $this->entry($client, $author, 'Unlisted archive marker', ContentEntry::TYPE_NEWS, ContentEntry::STATUS_PUBLISHED, $visibleDate, true),
                $this->entry($client, $author, 'Expired archive marker', ContentEntry::TYPE_NEWS, ContentEntry::STATUS_PUBLISHED, $visibleDate, false, new \DateTimeImmutable('1985-05-31 23:59:59')),
                $this->entry($client, $author, 'Page archive marker', ContentEntry::TYPE_PAGE, ContentEntry::STATUS_PUBLISHED, $visibleDate),
            ];
            foreach ($hidden as $entry) {
                $entryIds[] = $entry->getId();
            }

            $client->request('GET', '/news/archive/1985/05');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Visible archive marker');
            foreach ([
                'Draft archive marker',
                'Scheduled archive marker',
                'Future archive marker',
                'Archived archive marker',
                'Trashed archive marker',
                'Unlisted archive marker',
                'Expired archive marker',
                'Page archive marker',
            ] as $marker) {
                self::assertSelectorTextNotContains('body', $marker);
            }
        } finally {
            $this->cleanup($client, $entryIds, $authorId);
        }
    }

    public function testInvalidAndDisabledArchiveRoutesAreNotFound(): void
    {
        $client = static::createClient();

        $client->request('GET', '/news/archive/1985/00');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/news/archive/1985/13');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/news/archive/1985/05');
        self::assertResponseStatusCodeSame(404);

        $em = $this->em($client);
        $state = $em->getRepository(CmsModuleState::class)->find('content');
        $createdState = !$state instanceof CmsModuleState;
        $originalEnabled = $state?->isEnabled() ?? true;
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())
                ->setModuleKey('content')
                ->updateVersion('1.0.0');
        }
        $state->setEnabled(false);
        $em->persist($state);
        $em->flush();

        try {
            $client->request('GET', '/news/archive');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/news/archive/1985/05');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $em = $this->em($client);
            $em->clear();
            $state = $em->getRepository(CmsModuleState::class)->find('content');
            if ($state instanceof CmsModuleState) {
                if ($createdState) {
                    $em->remove($state);
                } else {
                    $state->setEnabled($originalEnabled);
                }
                $em->flush();
            }
        }
    }

    /**
     * @return list<string>
     */
    private function archiveTitles(KernelBrowser $client): array
    {
        return $client->getCrawler()
            ->filter('.news-grid article h2 a')
            ->each(static fn (Crawler $node): string => trim($node->text()));
    }

    private function author(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('news-archive-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('News archive test')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function entry(
        KernelBrowser $client,
        User $author,
        string $title,
        string $type,
        string $status,
        ?\DateTimeImmutable $publishedAt,
        bool $unlisted = false,
        ?\DateTimeImmutable $scheduledUnpublishAt = null,
        ?\DateTimeImmutable $scheduledAt = null,
    ): ContentEntry {
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType($type)
            ->setTitle($title)
            ->setSlug('news-archive-'.bin2hex(random_bytes(6)))
            ->setExcerpt($title)
            ->setBody($title)
            ->setStatus($status)
            ->setPublishedAt($publishedAt)
            ->setScheduledUnpublishAt($scheduledUnpublishAt)
            ->setScheduledAt($scheduledAt)
            ->setUnlisted($unlisted);
        $this->em($client)->persist($entry);
        $this->em($client)->flush();

        return $entry;
    }

    /**
     * @param list<int|null> $entryIds
     */
    private function cleanup(KernelBrowser $client, array $entryIds, ?int $authorId): void
    {
        $em = $this->em($client);
        $em->clear();
        foreach ($entryIds as $entryId) {
            if ($entryId === null) {
                continue;
            }
            $entry = $em->find(ContentEntry::class, $entryId);
            if ($entry instanceof ContentEntry) {
                $em->remove($entry);
            }
        }
        $em->flush();

        if ($authorId !== null) {
            $author = $em->find(User::class, $authorId);
            if ($author instanceof User) {
                $em->remove($author);
                $em->flush();
            }
        }
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
