<?php

declare(strict_types=1);

namespace App\Tests\ContentOperations;

use App\ContentOperations\EditorialWorkQueue;
use App\Entity\ContentEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EditorialWorkQueueTest extends WebTestCase
{
    public function testWorkTypesSummaryFiltersAndStableBoundedPagination(): void
    {
        $client = static::createClient();
        $author = $this->author($client);
        $authorId = $author->getId();
        $entryIds = [];
        $entries = [];
        $marker = 'editorial-queue-'.bin2hex(random_bytes(6));
        $now = new \DateTimeImmutable('+30 days');
        $slugPrefix = 'editorial-queue-'.bin2hex(random_bytes(6));

        try {
            $entries[] = $this->entry($author, $marker.' review', $slugPrefix.'-review', ContentEntry::STATUS_REVIEW);
            $entries[] = $this->entry($author, $marker.' stale draft', $slugPrefix.'-draft', ContentEntry::STATUS_DRAFT);
            $entries[] = $this->entry($author, $marker.' due publication', $slugPrefix.'-publication-due', ContentEntry::STATUS_SCHEDULED, ContentEntry::TYPE_NEWS, $now->sub(new \DateInterval('PT1S')));
            $entries[] = $this->entry($author, $marker.' upcoming publication', $slugPrefix.'-publication-upcoming', ContentEntry::STATUS_SCHEDULED, ContentEntry::TYPE_PAGE, $now->add(new \DateInterval('P7D')));
            $entries[] = $this->entry($author, $marker.' due unpublication', $slugPrefix.'-unpublication-due', ContentEntry::STATUS_PUBLISHED, ContentEntry::TYPE_NEWS, null, $now->modify('-1 second'));
            $entries[] = $this->entry($author, $marker.' upcoming unpublication', $slugPrefix.'-unpublication-upcoming', ContentEntry::STATUS_PUBLISHED, ContentEntry::TYPE_NEWS, null, $now->modify('+7 days'));
            $entries[] = $this->entry($author, $marker.' too far in future', $slugPrefix.'-future', ContentEntry::STATUS_SCHEDULED, ContentEntry::TYPE_NEWS, $now->add(new \DateInterval('P8D')));
            $entries[] = $this->entry($author, $marker.' archived control', $slugPrefix.'-archived', ContentEntry::STATUS_ARCHIVED);

            for ($index = 0; $index < 25; ++$index) {
                $entries[] = $this->entry($author, $marker.' review '.$index, $slugPrefix.'-review-'.$index, ContentEntry::STATUS_REVIEW);
            }

            $em = $this->em($client);
            foreach ($entries as $entry) {
                $em->persist($entry);
            }
            $em->flush();

            foreach ($entries as $entry) {
                if ($entry->getId() === null) {
                    throw new \LogicException('An editorial work queue fixture was not persisted.');
                }
                $entryIds[] = $entry->getId();
            }

            $queue = $client->getContainer()->get(EditorialWorkQueue::class);
            $firstPage = $queue->paginate(null, null, null, $marker, 1, $now);
            self::assertSame(31, $firstPage['total']);
            self::assertSame(31, $firstPage['filtered_total']);
            self::assertSame(26, $firstPage['counts'][EditorialWorkQueue::KIND_REVIEW]);
            self::assertSame(1, $firstPage['counts'][EditorialWorkQueue::KIND_STALE_DRAFT]);
            self::assertSame(1, $firstPage['counts'][EditorialWorkQueue::KIND_PUBLICATION_DUE]);
            self::assertSame(1, $firstPage['counts'][EditorialWorkQueue::KIND_PUBLICATION_UPCOMING]);
            self::assertSame(1, $firstPage['counts'][EditorialWorkQueue::KIND_UNPUBLICATION_DUE]);
            self::assertSame(1, $firstPage['counts'][EditorialWorkQueue::KIND_UNPUBLICATION_UPCOMING]);
            self::assertCount(EditorialWorkQueue::PAGE_SIZE, $firstPage['items']);
            self::assertTrue($firstPage['has_next']);

            $repeatPage = $queue->paginate(null, null, null, $marker, 1, $now);
            self::assertSame(
                array_map(static fn (array $item): ?int => $item['entry']->getId(), $firstPage['items']),
                array_map(static fn (array $item): ?int => $item['entry']->getId(), $repeatPage['items']),
            );

            $secondPage = $queue->paginate(null, null, null, $marker, 2, $now);
            self::assertCount(6, $secondPage['items']);
            self::assertFalse($secondPage['has_next']);
            self::assertSame(
                [],
                array_intersect(
                    array_map(static fn (array $item): ?int => $item['entry']->getId(), $firstPage['items']),
                    array_map(static fn (array $item): ?int => $item['entry']->getId(), $secondPage['items']),
                ),
            );

            $reviewPage = $queue->paginate(ContentEntry::STATUS_REVIEW, null, EditorialWorkQueue::KIND_REVIEW, $marker, 1, $now);
            self::assertSame(26, $reviewPage['counts'][EditorialWorkQueue::KIND_REVIEW]);
            self::assertSame(26, $reviewPage['filtered_total']);
            self::assertCount(EditorialWorkQueue::PAGE_SIZE, $reviewPage['items']);

            $pageFilter = $queue->paginate(null, ContentEntry::TYPE_PAGE, EditorialWorkQueue::KIND_PUBLICATION_UPCOMING, $marker, 1, $now);
            self::assertSame(1, $pageFilter['filtered_total']);
            self::assertSame(
                EditorialWorkQueue::KIND_PUBLICATION_UPCOMING,
                $pageFilter['items'][0]['kind'],
            );
        } finally {
            $this->cleanup($client, $entryIds, $authorId);
        }
    }

    private function author(KernelBrowser $client): User
    {
        $author = (new User())
            ->setEmail('editorial-queue-author-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Editorial queue fixture')
            ->verifyEmail();
        $this->em($client)->persist($author);
        $this->em($client)->flush();

        return $author;
    }

    private function entry(
        User $author,
        string $title,
        string $slug,
        string $status,
        string $type = ContentEntry::TYPE_NEWS,
        ?\DateTimeImmutable $scheduledAt = null,
        ?\DateTimeImmutable $scheduledUnpublishAt = null,
    ): ContentEntry {
        return (new ContentEntry())
            ->setAuthor($author)
            ->setType($type)
            ->setTitle($title)
            ->setSlug($slug)
            ->setBody('Queue fixture content.')
            ->setStatus($status)
            ->setPublishedAt($status === ContentEntry::STATUS_PUBLISHED ? new \DateTimeImmutable('-1 day') : null)
            ->setScheduledAt($scheduledAt)
            ->setScheduledUnpublishAt($scheduledUnpublishAt);
    }

    /**
     * @param list<int> $entryIds
     */
    private function cleanup(KernelBrowser $client, array $entryIds, ?int $authorId): void
    {
        $em = $this->em($client);
        $em->clear();
        foreach ($entryIds as $entryId) {
            $entry = $em->find(ContentEntry::class, $entryId);
            if ($entry instanceof ContentEntry) {
                $em->remove($entry);
            }
        }
        if ($authorId !== null) {
            $author = $em->find(User::class, $authorId);
            if ($author instanceof User) {
                $em->remove($author);
            }
        }
        $em->flush();
        $em->clear();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
