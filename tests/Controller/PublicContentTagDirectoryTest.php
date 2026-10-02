<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PublicContentTagDirectoryTest extends WebTestCase
{
    public function testAnonymousDirectoryListsOnlyTagsWithVisiblePublishedContent(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->em($client);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement('DELETE FROM content_entry_tag');
            $connection->executeStatement('DELETE FROM content_tag');
            $entityManager->clear();

            $suffix = bin2hex(random_bytes(5));
            $author = $this->createAuthor($entityManager, $suffix);
            $tags = [
                (new ContentTag())->setName('Alpha '.$suffix)->setSlug('directory-alpha-first-'.$suffix)->setDescription('First tag description '.$suffix),
                (new ContentTag())->setName('Alpha '.$suffix)->setSlug('directory-alpha-second-'.$suffix),
                (new ContentTag())->setName('Beta '.$suffix)->setSlug('directory-beta-'.$suffix)->setDescription('Beta tag description '.$suffix),
                (new ContentTag())->setName('Zeta '.$suffix)->setSlug('directory-zeta-'.$suffix),
            ];
            $orphan = (new ContentTag())
                ->setName('Orphan secret '.$suffix)
                ->setSlug('directory-orphan-'.$suffix)
                ->setDescription('Orphan-only description '.$suffix);
            $draftOnly = (new ContentTag())
                ->setName('Draft-only secret '.$suffix)
                ->setSlug('directory-draft-only-'.$suffix)
                ->setDescription('Draft-only description '.$suffix);
            $unlistedOnly = (new ContentTag())
                ->setName('Unlisted secret '.$suffix)
                ->setSlug('directory-unlisted-only-'.$suffix);
            $expiredOnly = (new ContentTag())
                ->setName('Expired secret '.$suffix)
                ->setSlug('directory-expired-only-'.$suffix);
            $futureOnly = (new ContentTag())
                ->setName('Future secret '.$suffix)
                ->setSlug('directory-future-only-'.$suffix);

            foreach ([...$tags, $orphan, $draftOnly, $unlistedOnly, $expiredOnly, $futureOnly] as $tag) {
                $entityManager->persist($tag);
            }
            foreach ($tags as $index => $tag) {
                $this->createEntry($entityManager, $author, $tag, 'visible-'.$index.'-'.$suffix);
            }
            $this->createEntry($entityManager, $author, $draftOnly, 'draft-'.$suffix, ContentEntry::STATUS_DRAFT);
            $this->createEntry($entityManager, $author, $unlistedOnly, 'unlisted-'.$suffix, ContentEntry::STATUS_PUBLISHED, true);
            $this->createEntry(
                $entityManager,
                $author,
                $expiredOnly,
                'expired-'.$suffix,
                ContentEntry::STATUS_PUBLISHED,
                false,
                new \DateTimeImmutable('-1 hour'),
                new \DateTimeImmutable('-30 minutes'),
            );
            $this->createEntry(
                $entityManager,
                $author,
                $futureOnly,
                'future-'.$suffix,
                ContentEntry::STATUS_PUBLISHED,
                false,
                new \DateTimeImmutable('+1 day'),
            );
            $entityManager->flush();

            $crawler = $client->request('GET', '/news/tags');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Tags');
            self::assertSelectorTextContains('body', 'First tag description '.$suffix);
            self::assertSelectorTextContains('body', 'Beta tag description '.$suffix);
            self::assertStringNotContainsString('Orphan secret '.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('Orphan-only description '.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('Draft-only secret '.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('Unlisted secret '.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('Expired secret '.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('Future secret '.$suffix, (string) $client->getResponse()->getContent());
            self::assertCount(2, $crawler->filter('.tag-directory-item .tag-description'));

            /** @var list<array{name: string, href: string|null}> $items */
            $items = $crawler->filter('.tag-directory-item')->each(
                static fn (Crawler $card): array => [
                    'name' => trim($card->filter('h2')->text()),
                    'href' => $card->filter('h2 a')->attr('href'),
                ],
            );
            $fixtureLinks = [];
            foreach ($items as $item) {
                if (str_contains($item['name'], $suffix) && $item['href'] !== null) {
                    $fixtureLinks[] = $item['href'];
                }
            }

            self::assertSame(array_map(static fn (ContentTag $tag): string => '/news/tag/'.$tag->getSlug(), $tags), $fixtureLinks);
            self::assertSelectorNotExists('.tag-directory-item form, .tag-directory-item button');

            $client->request('POST', '/news/tags');
            self::assertResponseStatusCodeSame(405);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testDirectoryPaginatesTwentyVisibleTagsAndRejectsInvalidPages(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->em($client);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement('DELETE FROM content_entry_tag');
            $connection->executeStatement('DELETE FROM content_tag');
            $entityManager->clear();

            $suffix = bin2hex(random_bytes(5));
            $author = $this->createAuthor($entityManager, $suffix);
            $tags = [];
            for ($index = 1; $index <= 21; ++$index) {
                $tag = (new ContentTag())
                    ->setName(sprintf('Directory %s %02d', $suffix, $index))
                    ->setSlug(sprintf('directory-page-%s-%02d', $suffix, $index));
                $entityManager->persist($tag);
                $tags[] = $tag;
                $this->createEntry($entityManager, $author, $tag, sprintf('page-%s-%02d', $suffix, $index));
            }
            $orphan = (new ContentTag())
                ->setName('Page orphan '.$suffix)
                ->setSlug('directory-page-orphan-'.$suffix);
            $entityManager->persist($orphan);
            $this->createEntry($entityManager, $author, $orphan, 'page-draft-'.$suffix, ContentEntry::STATUS_DRAFT);
            $entityManager->flush();

            $expectedNames = array_map(static fn (ContentTag $tag): string => $tag->getName(), $tags);
            $crawler = $client->request('GET', '/news/tags');
            self::assertResponseIsSuccessful();
            $firstPage = $crawler->filter('.tag-directory-item h2')->each(
                static fn (Crawler $node): string => trim($node->text()),
            );
            self::assertCount(20, $firstPage);
            self::assertSame(array_slice($expectedNames, 0, 20), $firstPage);
            self::assertSelectorTextContains('.tag-directory-pagination', 'Seite 1 von 2');
            self::assertSelectorExists('.tag-directory-pagination a[rel="next"]');
            self::assertStringNotContainsString('Page orphan '.$suffix, (string) $client->getResponse()->getContent());

            $crawler = $client->request('GET', '/news/tags?page=2');
            self::assertResponseIsSuccessful();
            $secondPage = $crawler->filter('.tag-directory-item h2')->each(
                static fn (Crawler $node): string => trim($node->text()),
            );
            self::assertSame(array_slice($expectedNames, 20), $secondPage);
            self::assertSelectorTextContains('.tag-directory-pagination', 'Seite 2 von 2');
            self::assertSelectorExists('.tag-directory-pagination a[rel="prev"]');
            self::assertSelectorNotExists('.tag-directory-pagination a[rel="next"]');

            $client->request('GET', '/news/tags?page=3');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/news/tags?page=invalid');
            self::assertResponseStatusCodeSame(400);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testDirectoryShowsUsefulEmptyStateWhenNoTagsExist(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $this->em($client);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement('DELETE FROM content_entry_tag');
            $connection->executeStatement('DELETE FROM content_tag');
            $entityManager->clear();

            $client->request('GET', '/news/tags');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Tags');
            self::assertSelectorTextContains('body', 'Noch keine Tags vorhanden.');
            self::assertSelectorNotExists('.tag-directory-item');
            self::assertSelectorNotExists('form');
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testDisabledContentModuleReturnsNotFoundWithoutTagContents(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $tag = (new ContentTag())
            ->setName('Hidden tag directory '.$suffix)
            ->setSlug('hidden-tag-directory-'.$suffix);
        $entityManager = $this->em($client);
        $entityManager->persist($tag);
        $entityManager->flush();
        $tagSlug = $tag->getSlug();

        $state = $entityManager->getRepository(CmsModuleState::class)->find('content');
        $previousEnabled = $state?->isEnabled();
        if ($state === null) {
            $state = (new CmsModuleState())
                ->setModuleKey('content')
                ->updateVersion('1.0.0');
            $entityManager->persist($state);
        }
        $state->setEnabled(false);
        $entityManager->flush();

        try {
            $client->request('GET', '/news/tags');

            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('Hidden tag directory '.$suffix, (string) $client->getResponse()->getContent());
        } finally {
            $this->restoreContentModule($client, $previousEnabled);
            $this->removeTags($client, [$tagSlug]);
        }
    }

    private function createAuthor(EntityManagerInterface $entityManager, string $suffix): User
    {
        $author = (new User())
            ->setEmail('public-tag-directory-'.$suffix.'@example.test')
            ->setDisplayName('Public tag directory '.$suffix)
            ->setPermissions([])
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $entityManager->persist($author);

        return $author;
    }

    private function createEntry(
        EntityManagerInterface $entityManager,
        User $author,
        ContentTag $tag,
        string $suffix,
        string $status = ContentEntry::STATUS_PUBLISHED,
        bool $unlisted = false,
        ?\DateTimeImmutable $publishedAt = null,
        ?\DateTimeImmutable $scheduledUnpublishAt = null,
    ): ContentEntry {
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Directory entry '.$suffix)
            ->setSlug('directory-entry-'.$suffix)
            ->setBody('Synthetic public directory test content.')
            ->setStatus($status)
            ->setUnlisted($unlisted)
            ->addTag($tag);

        if ($status === ContentEntry::STATUS_PUBLISHED) {
            $entry
                ->setPublishedAt($publishedAt ?? new \DateTimeImmutable('-1 hour'))
                ->setScheduledUnpublishAt($scheduledUnpublishAt);
        }

        $entityManager->persist($entry);

        return $entry;
    }

    /** @param list<string> $slugs */
    private function removeTags(KernelBrowser $client, array $slugs): void
    {
        $entityManager = $this->em($client);
        $entityManager->clear();
        foreach ($slugs as $slug) {
            $tag = $entityManager->getRepository(ContentTag::class)->findOneBy(['slug' => $slug]);
            if ($tag instanceof ContentTag) {
                $entityManager->remove($tag);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    private function restoreContentModule(KernelBrowser $client, ?bool $previousEnabled): void
    {
        $entityManager = $this->em($client);
        $entityManager->clear();
        $state = $entityManager->getRepository(CmsModuleState::class)->find('content');
        if ($previousEnabled === null) {
            if ($state !== null) {
                $entityManager->remove($state);
            }
        } elseif ($state !== null) {
            $state->setEnabled($previousEnabled);
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
