<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Entity\User;
use App\Layout\LayoutValidator;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use App\Widget\Content\PublicContentTagQuery;
use App\Widget\PublicContentTagWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class PublicContentTagWidgetProviderTest extends WebTestCase
{
    public function testWidgetIsDiscoverableAndRendersOnlyVisibleTagsSafely(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureContentModuleState($client);
        $this->setContentModuleEnabled($client, true);

        $token = bin2hex(random_bytes(8));
        $tagSlugs = [];
        $entrySlugs = [];
        $userIds = [];

        try {
            $author = $this->createUser($client, 'wcp611-author-'.$token.'@example.test', 'WCP611 author '.$token);
            $userIds[] = $this->requireId($author->getId());

            $editor = $this->createUser(
                $client,
                'wcp611-editor-'.$token.'@example.test',
                'WCP611 editor '.$token,
                [CmsPermission::ACCESS, CmsPermission::SETTINGS],
            );
            $userIds[] = $this->requireId($editor->getId());

            $newsTag = $this->createTag(
                $client,
                'wcp611-news-'.$token,
                '!!!WCP611 <script>'.$token.'</script>',
                '<script>WCP611 description '.$token.'</script>',
            );
            $tagSlugs[] = $newsTag->getSlug();
            $pageTag = $this->createTag($client, 'wcp611-page-'.$token, '!!!WCP611 page '.$token);
            $tagSlugs[] = $pageTag->getSlug();
            $sharedTag = $this->createTag($client, 'wcp611-shared-'.$token, '!!!WCP611 shared '.$token);
            $tagSlugs[] = $sharedTag->getSlug();

            $hiddenTags = [];
            /** @var list<array{string, string, string, bool, ?\DateTimeImmutable, ?\DateTimeImmutable}> $hiddenCases */
            $hiddenCases = [
                ['draft', ContentEntry::TYPE_NEWS, ContentEntry::STATUS_DRAFT, false, null, null],
                ['review', ContentEntry::TYPE_PAGE, ContentEntry::STATUS_REVIEW, false, null, null],
                ['scheduled', ContentEntry::TYPE_NEWS, ContentEntry::STATUS_SCHEDULED, false, null, null],
                ['future', ContentEntry::TYPE_PAGE, ContentEntry::STATUS_PUBLISHED, false, new \DateTimeImmutable('+1 day'), null],
                ['archived', ContentEntry::TYPE_NEWS, ContentEntry::STATUS_ARCHIVED, false, null, null],
                ['trashed', ContentEntry::TYPE_PAGE, ContentEntry::STATUS_TRASHED, false, null, null],
                ['unlisted', ContentEntry::TYPE_NEWS, ContentEntry::STATUS_PUBLISHED, true, new \DateTimeImmutable('-1 hour'), null],
                ['expired', ContentEntry::TYPE_PAGE, ContentEntry::STATUS_PUBLISHED, false, new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('-1 minute')],
            ];

            foreach ($hiddenCases as [$suffix, $type, $status, $unlisted, $publishedAt, $scheduledUnpublishAt]) {
                $tag = $this->createTag($client, 'wcp611-'.$suffix.'-'.$token, '!!!WCP611 hidden '.$suffix.' '.$token);
                $tagSlugs[] = $tag->getSlug();
                $hiddenTags[] = $tag;
                $entry = $this->createEntry(
                    $client,
                    $author,
                    $tag,
                    'wcp611-'.$suffix.'-'.$token,
                    'Hidden '.$suffix.' '.$token,
                    $type,
                    $status,
                    $unlisted,
                    $publishedAt,
                    $scheduledUnpublishAt,
                );
                $entrySlugs[] = $entry->getSlug();
            }

            $orphanTag = $this->createTag($client, 'wcp611-orphan-'.$token, '!!!WCP611 orphan '.$token);
            $tagSlugs[] = $orphanTag->getSlug();

            $news = $this->createEntry(
                $client,
                $author,
                $newsTag,
                'wcp611-visible-news-'.$token,
                'Visible news '.$token,
                ContentEntry::TYPE_NEWS,
            );
            $entrySlugs[] = $news->getSlug();

            $page = $this->createEntry(
                $client,
                $author,
                $pageTag,
                'wcp611-visible-page-'.$token,
                'Visible page '.$token,
                ContentEntry::TYPE_PAGE,
            );
            $entrySlugs[] = $page->getSlug();

            $sharedNews = $this->createEntry(
                $client,
                $author,
                $sharedTag,
                'wcp611-shared-news-'.$token,
                'Shared news '.$token,
                ContentEntry::TYPE_NEWS,
            );
            $sharedPage = $this->createEntry(
                $client,
                $author,
                $sharedTag,
                'wcp611-shared-page-'.$token,
                'Shared page '.$token,
                ContentEntry::TYPE_PAGE,
            );
            $entrySlugs[] = $sharedNews->getSlug();
            $entrySlugs[] = $sharedPage->getSlug();

            $this->entityManager($client)->flush();

            $client->loginUser($editor);
            $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();

            $container = $client->getContainer();
            $registry = $container->get(WidgetRegistry::class);
            $definition = $registry->get(PublicContentTagWidgetProvider::KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertSame('content', $definition->module);
            self::assertTrue($registry->available(PublicContentTagWidgetProvider::KEY));

            $availableKeys = array_map(
                static fn (WidgetDefinition $item): string => $item->key,
                $registry->availableDefinitions(),
            );
            self::assertContains(PublicContentTagWidgetProvider::KEY, $availableKeys);

            $schema = $container->get(LayoutValidator::class)->widgetSchema(PublicContentTagWidgetProvider::KEY);
            self::assertSame(6, $schema['count']['default']);
            self::assertSame(1, $schema['count']['min']);
            self::assertSame(PublicContentTagQuery::MAX_ITEMS, $schema['count']['max']);

            $data = $registry->data(PublicContentTagWidgetProvider::KEY, ['count' => 12]);
            /** @var list<ContentTag> $items */
            $items = $data['items'];
            $itemIds = array_map(static fn (ContentTag $tag): ?int => $tag->getId(), $items);
            self::assertContains($this->requireId($newsTag->getId()), $itemIds);
            self::assertContains($this->requireId($pageTag->getId()), $itemIds);
            self::assertContains($this->requireId($sharedTag->getId()), $itemIds);

            foreach ($hiddenTags as $tag) {
                self::assertNotContains($this->requireId($tag->getId()), $itemIds);
            }
            self::assertNotContains($this->requireId($orphanTag->getId()), $itemIds);

            $markup = $container->get(Environment::class)->render($definition->template, [
                'widget' => ['id' => 'content-tag-'.$token],
                'config' => ['count' => 12],
                'data' => $data,
            ]);
            self::assertStringContainsString('href="/content/tag/'.$newsTag->getSlug().'"', $markup);
            self::assertStringContainsString('&lt;script&gt;WCP611 description '.$token.'&lt;/script&gt;', $markup);
            self::assertStringNotContainsString('<script>WCP611 description '.$token.'</script>', $markup);
            self::assertStringNotContainsString('<script>'.$token.'</script>', $markup);

            $emptyMarkup = $container->get(Environment::class)->render($definition->template, [
                'widget' => ['id' => 'content-tag-empty-'.$token],
                'config' => ['count' => 6],
                'data' => ['items' => []],
            ]);
            self::assertStringContainsString('role="status"', $emptyMarkup);
            self::assertStringContainsString('keine öffentlichen Schlagwörter verfügbar', $emptyMarkup);
        } finally {
            $this->removeFixtures($client, $entrySlugs, $tagSlugs, $userIds);
            $this->restoreContentModuleState($client, $moduleSnapshot);
        }
    }

    public function testQueryReturnsDistinctOrderedTagsAndClampsItsLimit(): void
    {
        $client = static::createClient();
        $query = $client->getContainer()->get(PublicContentTagQuery::class);
        $token = bin2hex(random_bytes(8));
        $tagSlugs = [];
        $entrySlugs = [];
        $userIds = [];

        try {
            $author = $this->createUser($client, 'wcp611-query-'.$token.'@example.test', 'WCP611 query '.$token);
            $userIds[] = $this->requireId($author->getId());

            $tags = [];
            for ($index = 0; $index < 15; ++$index) {
                $name = $index < 2
                    ? '!!!WCP611 '.$token.' duplicate'
                    : sprintf('!!!WCP611 %s tag-%02d', $token, $index);
                $slug = sprintf('wcp611-query-%s-tag-%02d', $token, $index);
                $tag = $this->createTag($client, $slug, $name);
                $tags[] = $tag;
                $tagSlugs[] = $tag->getSlug();
            }
            $primaryTag = $tags[0] ?? null;
            if (!$primaryTag instanceof ContentTag) {
                throw new \LogicException('The query test requires a tag fixture.');
            }

            $extraTags = array_slice($tags, 1);
            $news = $this->createEntry(
                $client,
                $author,
                $primaryTag,
                'wcp611-query-news-'.$token,
                'Query news '.$token,
                ContentEntry::TYPE_NEWS,
                ContentEntry::STATUS_PUBLISHED,
                false,
                new \DateTimeImmutable('-1 hour'),
                null,
                $extraTags,
            );
            $page = $this->createEntry(
                $client,
                $author,
                $primaryTag,
                'wcp611-query-page-'.$token,
                'Query page '.$token,
                ContentEntry::TYPE_PAGE,
                ContentEntry::STATUS_PUBLISHED,
                false,
                new \DateTimeImmutable('-1 hour'),
                null,
                $extraTags,
            );
            $entrySlugs[] = $news->getSlug();
            $entrySlugs[] = $page->getSlug();
            $this->entityManager($client)->flush();

            $expected = $tags;
            usort($expected, static function (ContentTag $left, ContentTag $right): int {
                $nameOrder = strcmp($left->getName(), $right->getName());

                return $nameOrder !== 0 ? $nameOrder : ($left->getId() <=> $right->getId());
            });
            $expectedIds = array_map(
                fn (ContentTag $tag): int => $this->requireId($tag->getId()),
                array_slice($expected, 0, PublicContentTagQuery::MAX_ITEMS),
            );

            $result = $query->findPublic(500);
            $resultIds = array_map(
                fn (ContentTag $tag): int => $this->requireId($tag->getId()),
                $result,
            );
            self::assertSame($expectedIds, $resultIds);
            self::assertCount(PublicContentTagQuery::MAX_ITEMS, $result);
            self::assertSame($resultIds, array_values(array_unique($resultIds)));
            self::assertCount(6, $query->findPublic());
            self::assertCount(1, $query->findPublic(0));
        } finally {
            $this->removeFixtures($client, $entrySlugs, $tagSlugs, $userIds);
        }
    }

    public function testWidgetIsUnavailableWhenTheContentModuleIsDisabled(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureContentModuleState($client);
        $this->setContentModuleEnabled($client, false);

        try {
            $registry = $client->getContainer()->get(WidgetRegistry::class);
            self::assertFalse($registry->available(PublicContentTagWidgetProvider::KEY));
            self::assertNotContains(
                PublicContentTagWidgetProvider::KEY,
                array_map(
                    static fn (WidgetDefinition $item): string => $item->key,
                    $registry->availableDefinitions(),
                ),
            );
            self::assertSame([], $registry->data(PublicContentTagWidgetProvider::KEY, ['count' => 6]));
        } finally {
            $this->restoreContentModuleState($client, $moduleSnapshot);
        }
    }

    /**
     * @param list<ContentTag> $extraTags
     */
    private function createEntry(
        KernelBrowser $client,
        User $author,
        ContentTag $primaryTag,
        string $slug,
        string $title,
        string $type,
        string $status = ContentEntry::STATUS_PUBLISHED,
        bool $unlisted = false,
        ?\DateTimeImmutable $publishedAt = null,
        ?\DateTimeImmutable $scheduledUnpublishAt = null,
        array $extraTags = [],
    ): ContentEntry {
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType($type)
            ->setTitle($title)
            ->setSlug($slug)
            ->setBody('Synthetic content '.$title)
            ->setStatus($status)
            ->setUnlisted($unlisted)
            ->addTag($primaryTag);

        foreach ($extraTags as $tag) {
            $entry->addTag($tag);
        }

        if ($status === ContentEntry::STATUS_PUBLISHED) {
            $entry->setPublishedAt($publishedAt ?? new \DateTimeImmutable('-1 hour'));
        } elseif ($status === ContentEntry::STATUS_SCHEDULED) {
            $entry->setScheduledAt(new \DateTimeImmutable('+1 day'));
        }
        if ($scheduledUnpublishAt !== null) {
            $entry->setScheduledUnpublishAt($scheduledUnpublishAt);
        }

        $this->entityManager($client)->persist($entry);

        return $entry;
    }

    private function createTag(KernelBrowser $client, string $slug, string $name, ?string $description = null): ContentTag
    {
        $tag = (new ContentTag())
            ->setSlug($slug)
            ->setName($name)
            ->setDescription($description);
        $this->entityManager($client)->persist($tag);

        return $tag;
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(
        KernelBrowser $client,
        string $email,
        string $displayName,
        array $permissions = [],
    ): User {
        $user = (new User())
            ->setEmail($email)
            ->setDisplayName($displayName)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    /**
     * @param list<string> $entrySlugs
     * @param list<string> $tagSlugs
     * @param list<int> $userIds
     */
    private function removeFixtures(KernelBrowser $client, array $entrySlugs, array $tagSlugs, array $userIds): void
    {
        $entityManager = $this->entityManager($client);
        if (!$entityManager->isOpen()) {
            return;
        }

        $entityManager->clear();
        foreach ($entrySlugs as $slug) {
            $entry = $entityManager->getRepository(ContentEntry::class)->findOneBy(['slug' => $slug]);
            if ($entry instanceof ContentEntry) {
                $entry->clearTags();
                $entityManager->remove($entry);
            }
        }
        $entityManager->flush();

        $entityManager->clear();
        foreach ($tagSlugs as $slug) {
            $tag = $entityManager->getRepository(ContentTag::class)->findOneBy(['slug' => $slug]);
            if ($tag instanceof ContentTag) {
                $entityManager->remove($tag);
            }
        }
        $entityManager->flush();

        $entityManager->clear();
        foreach ($userIds as $id) {
            $user = $entityManager->find(User::class, $id);
            if ($user instanceof User) {
                $entityManager->remove($user);
            }
        }
        $entityManager->flush();
    }

    /**
     * @return array{exists: bool, enabled: bool}
     */
    private function captureContentModuleState(KernelBrowser $client): array
    {
        if (!$client->getContainer()->get(CmsModuleManager::class)->isInstalled('content')) {
            self::markTestSkipped('The Content module must be installed for this widget test.');
        }

        $state = $this->entityManager($client)->find(CmsModuleState::class, 'content');

        return ['exists' => $state !== null, 'enabled' => $state?->isEnabled() ?? true];
    }

    private function setContentModuleEnabled(KernelBrowser $client, bool $enabled): void
    {
        $entityManager = $this->entityManager($client);
        $state = $entityManager->find(CmsModuleState::class, 'content');
        if ($state === null) {
            $state = (new CmsModuleState())
                ->setModuleKey('content')
                ->updateVersion('test');
            $entityManager->persist($state);
        }
        $state->setEnabled($enabled);
        $entityManager->flush();
    }

    /**
     * @param array{exists: bool, enabled: bool} $snapshot
     */
    private function restoreContentModuleState(KernelBrowser $client, array $snapshot): void
    {
        $entityManager = $this->entityManager($client);
        if (!$entityManager->isOpen()) {
            return;
        }

        $entityManager->clear();
        $state = $entityManager->find(CmsModuleState::class, 'content');
        if (!$snapshot['exists']) {
            if ($state instanceof CmsModuleState) {
                $entityManager->remove($state);
                $entityManager->flush();
            }

            return;
        }

        if ($state instanceof CmsModuleState && $state->isEnabled() !== $snapshot['enabled']) {
            $state->setEnabled($snapshot['enabled']);
            $entityManager->flush();
        }
    }

    private function requireId(?int $id): int
    {
        self::assertNotNull($id);

        return $id;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
