<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicNewsPrintControllerTest extends WebTestCase
{
    public function testPublishedNewsHasAnUncachedPrintViewWithEscapedContent(): void
    {
        $client = static::createClient();
        $entryIds = [];
        $authorId = null;

        try {
            $author = $this->author($client);
            $authorId = $author->getId();
            $entry = $this->entry($client, $author, 'Printable headline '.bin2hex(random_bytes(4)), 'printable-body-'.bin2hex(random_bytes(4)));
            $entryIds[] = $entry->getId();

            $client->request('GET', '/news/print/'.$entry->getSlug());

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main.news-print h1', $entry->getTitle());
            self::assertSelectorTextContains('article', $entry->getBody());
            self::assertSelectorExists('meta[name="robots"][content="noindex,nofollow"]');
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
            self::assertResponseHeaderSame('Cache-Control', 'private, no-store');
            self::assertSelectorExists('style');
            self::assertSelectorExists('a.news-print__return[href="/news/'.$entry->getSlug().'"]');
        } finally {
            $this->cleanup($client, $entryIds, $authorId);
        }
    }

    public function testPrintViewRejectsUnpublishedUnlistedAndNonNewsEntries(): void
    {
        $client = static::createClient();
        $entryIds = [];
        $authorId = null;

        try {
            $author = $this->author($client);
            $authorId = $author->getId();
            $draft = $this->entry($client, $author, 'Private print draft', 'draft body', ContentEntry::STATUS_DRAFT);
            $unlisted = $this->entry($client, $author, 'Unlisted print article', 'unlisted body', ContentEntry::STATUS_PUBLISHED, true);
            $page = $this->entry($client, $author, 'Page print article', 'page body', ContentEntry::STATUS_PUBLISHED, false, ContentEntry::TYPE_PAGE);
            $entryIds = [$draft->getId(), $unlisted->getId(), $page->getId()];

            foreach ([$draft, $unlisted, $page] as $entry) {
                $client->request('GET', '/news/print/'.$entry->getSlug());
                self::assertResponseStatusCodeSame(404);
            }

            $client->request('GET', '/news/print/missing-print-article');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, $entryIds, $authorId);
        }
    }

    private function author(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('news-print-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('News print test')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function entry(
        KernelBrowser $client,
        User $author,
        string $title,
        string $body,
        string $status = ContentEntry::STATUS_PUBLISHED,
        bool $unlisted = false,
        string $type = ContentEntry::TYPE_NEWS,
    ): ContentEntry {
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType($type)
            ->setTitle($title)
            ->setSlug('news-print-'.bin2hex(random_bytes(6)))
            ->setExcerpt('Print excerpt')
            ->setBody($body)
            ->setStatus($status)
            ->setPublishedAt($status === ContentEntry::STATUS_PUBLISHED ? new \DateTimeImmutable('-1 hour') : null)
            ->setUnlisted($unlisted);
        $this->em($client)->persist($entry);
        $this->em($client)->flush();

        return $entry;
    }

    /** @param list<int|null> $entryIds */
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
