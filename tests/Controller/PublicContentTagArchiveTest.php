<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PublicContentTagArchiveTest extends WebTestCase
{
    public function testNewsAndPageDetailsLinkToSharedArchiveWhileLegacyNewsRouteStaysNewsOnly(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $news = $this->entry($client, $fixture, 'news', 'Tagged news', ContentEntry::TYPE_NEWS);
        $page = $this->entry($client, $fixture, 'page', 'Tagged page', ContentEntry::TYPE_PAGE);
        $this->em($client)->flush();

        try {
            $archivePath = '/content/tag/'.$fixture['tagSlug'];
            foreach ([
                '/news/'.$news->getSlug(),
                '/page/'.$page->getSlug(),
            ] as $detailPath) {
                $crawler = $client->request('GET', $detailPath);
                self::assertResponseIsSuccessful();
                $tagLink = $crawler->filter('nav[aria-label="Tags"] a');
                self::assertCount(1, $tagLink);
                self::assertSame($archivePath, $tagLink->attr('href'));
            }

            $crawler = $client->request('GET', $archivePath);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', '2 Inhalte');
            $titles = $crawler->filter('.news-grid article h2')->each(
                static fn (Crawler $node): string => trim($node->text()),
            );
            self::assertContains('Tagged page '.$fixture['suffix'], $titles);
            self::assertContains('Tagged news '.$fixture['suffix'], $titles);

            $crawler = $client->request('GET', '/news/tag/'.$fixture['tagSlug']);
            self::assertResponseIsSuccessful();
            $legacyContent = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Tagged news '.$fixture['suffix'], $legacyContent);
            self::assertStringNotContainsString('Tagged page '.$fixture['suffix'], $legacyContent);
        } finally {
            $this->cleanup($client, $fixture, [$news, $page]);
        }
    }

    public function testArchiveExcludesDraftScheduledFutureArchivedTrashedAndUnlistedEntries(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $hidden = [
            $this->entry($client, $fixture, 'draft', 'draft', ContentEntry::TYPE_NEWS, ContentEntry::STATUS_DRAFT),
            $this->entry($client, $fixture, 'review', 'review', ContentEntry::TYPE_PAGE, ContentEntry::STATUS_REVIEW),
            $this->entry($client, $fixture, 'scheduled', 'scheduled', ContentEntry::TYPE_NEWS, ContentEntry::STATUS_SCHEDULED),
            $this->entry($client, $fixture, 'future', 'future', ContentEntry::TYPE_PAGE, ContentEntry::STATUS_PUBLISHED, false, new \DateTimeImmutable('+1 day')),
            $this->entry($client, $fixture, 'archived', 'archived', ContentEntry::TYPE_NEWS, ContentEntry::STATUS_ARCHIVED),
            $this->entry($client, $fixture, 'trashed', 'trashed', ContentEntry::TYPE_PAGE, ContentEntry::STATUS_TRASHED),
            $this->entry($client, $fixture, 'unlisted', 'unlisted', ContentEntry::TYPE_NEWS, ContentEntry::STATUS_PUBLISHED, true),
        ];
        $this->em($client)->flush();

        try {
            $client->request('GET', '/content/tag/'.$fixture['tagSlug']);
            self::assertResponseIsSuccessful();
            $content = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('0 Inhalte', $content);
            self::assertStringContainsString('Keine veröffentlichten Inhalte vorhanden.', $content);

            foreach ($hidden as $entry) {
                self::assertStringNotContainsString($entry->getTitle(), $content);
            }
        } finally {
            $this->cleanup($client, $fixture, $hidden);
        }
    }

    public function testArchiveUsesStableNewestFirstPaginationAndRejectsOutOfRangePages(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $publishedAt = new \DateTimeImmutable('-1 hour');
        $entries = [];
        for ($index = 0; $index < 21; ++$index) {
            $entries[] = $this->entry(
                $client,
                $fixture,
                sprintf('item-%02d', $index),
                sprintf('Archive item %02d', $index),
                $index % 2 === 0 ? ContentEntry::TYPE_NEWS : ContentEntry::TYPE_PAGE,
                ContentEntry::STATUS_PUBLISHED,
                false,
                $publishedAt,
            );
        }
        $this->em($client)->flush();
        $expectedTitles = array_reverse(array_map(static fn (ContentEntry $entry): string => $entry->getTitle(), $entries));

        try {
            $crawler = $client->request('GET', '/content/tag/'.$fixture['tagSlug']);
            self::assertResponseIsSuccessful();
            $firstPage = $crawler->filter('.news-grid article h2')->each(
                static fn (Crawler $node): string => trim($node->text()),
            );
            self::assertCount(20, $firstPage);
            self::assertSame(array_slice($expectedTitles, 0, 20), $firstPage);
            self::assertSelectorTextContains('.pagination', 'Seite 1 von 2');

            $crawler = $client->request('GET', '/content/tag/'.$fixture['tagSlug'].'?page=2');
            self::assertResponseIsSuccessful();
            $secondPage = $crawler->filter('.news-grid article h2')->each(
                static fn (Crawler $node): string => trim($node->text()),
            );
            self::assertSame(array_slice($expectedTitles, 20), $secondPage);
            self::assertSelectorTextContains('.pagination', 'Seite 2 von 2');

            $client->request('GET', '/content/tag/'.$fixture['tagSlug'].'?page=3');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, $fixture, $entries);
        }
    }

    public function testEmptyArchiveIsReachableAndMissingTagReturnsNotFound(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);

        try {
            $client->request('GET', '/content/tag/'.$fixture['tagSlug']);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', '0 Inhalte');
            self::assertSelectorTextContains('body', 'Keine veröffentlichten Inhalte vorhanden.');

            $client->request('GET', '/content/tag/missing-'.$fixture['suffix']);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, $fixture, []);
        }
    }

    /**
     * @return array{user: User, userId: int, tag: ContentTag, tagSlug: string, suffix: string}
     */
    private function fixture(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(6));
        $user = (new User())
            ->setEmail('public-tag-archive-'.$suffix.'@example.test')
            ->setDisplayName('Public tag archive '.$suffix)
            ->setPermissions([])
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $tag = (new ContentTag())
            ->setName('Public tag '.$suffix)
            ->setSlug('public-tag-'.$suffix)
            ->setDescription('Synthetic tag description.');
        $em = $this->em($client);
        $em->persist($user);
        $em->persist($tag);
        $em->flush();

        return [
            'user' => $user,
            'userId' => $this->requireId($user->getId()),
            'tag' => $tag,
            'tagSlug' => $tag->getSlug(),
            'suffix' => $suffix,
        ];
    }

    /**
     * @param array{user: User, userId: int, tag: ContentTag, tagSlug: string, suffix: string} $fixture
     */
    private function entry(
        KernelBrowser $client,
        array $fixture,
        string $suffix,
        string $title,
        string $type,
        string $status = ContentEntry::STATUS_PUBLISHED,
        bool $unlisted = false,
        ?\DateTimeImmutable $publishedAt = null,
    ): ContentEntry {
        $slug = 'tagged-'.$fixture['suffix'].'-'.$suffix;
        $entry = (new ContentEntry())
            ->setAuthor($fixture['user'])
            ->setType($type)
            ->setTitle($title.' '.$fixture['suffix'])
            ->setSlug($slug)
            ->setBody('Synthetic body for '.$title.' '.$fixture['suffix'])
            ->setStatus($status)
            ->setUnlisted($unlisted)
            ->addTag($fixture['tag']);

        if ($status === ContentEntry::STATUS_PUBLISHED) {
            $entry->setPublishedAt($publishedAt ?? new \DateTimeImmutable('-1 hour'));
        } elseif ($status === ContentEntry::STATUS_SCHEDULED) {
            $entry->setScheduledAt(new \DateTimeImmutable('+1 day'));
        }

        $this->em($client)->persist($entry);

        return $entry;
    }

    /**
     * @param array{user: User, userId: int, tag: ContentTag, tagSlug: string, suffix: string} $fixture
     * @param list<ContentEntry> $entries
     */
    private function cleanup(KernelBrowser $client, array $fixture, array $entries): void
    {
        $em = $this->em($client);
        $em->clear();
        foreach ($entries as $entry) {
            $stored = $em->getRepository(ContentEntry::class)->findOneBy(['slug' => $entry->getSlug()]);
            if ($stored instanceof ContentEntry) {
                $stored->clearTags();
                $em->remove($stored);
            }
        }

        $tag = $em->getRepository(ContentTag::class)->findOneBy(['slug' => $fixture['tagSlug']]);
        if ($tag instanceof ContentTag) {
            $em->remove($tag);
        }
        $user = $em->find(User::class, $fixture['userId']);
        if ($user instanceof User) {
            $em->remove($user);
        }
        $em->flush();
        $em->clear();
    }

    private function requireId(?int $id): int
    {
        self::assertNotNull($id);

        return $id;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
