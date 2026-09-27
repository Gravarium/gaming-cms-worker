<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\Category;
use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\User;
use App\Layout\LayoutValidator;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use App\Widget\Content\PublicContentCategoryQuery;
use App\Widget\PublicContentCategoryWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class PublicContentCategoryWidgetProviderTest extends WebTestCase
{
    public function testWidgetIsAvailableInThePageBuilderAndRendersEscapedArchiveLinks(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureContentModuleState($client);
        $this->setContentModuleEnabled($client, true);
        $token = bin2hex(random_bytes(8));
        $categoryIds = [];
        $entryIds = [];
        $userIds = [];

        try {
            $author = $this->createUser($client, 'category-author-'.$token.'@example.test', 'Category author '.$token);
            $authorId = $author->getId();
            if ($authorId === null) {
                throw new \LogicException('Persisted author fixture has no database ID.');
            }
            $userIds[] = $authorId;

            $parent = $this->createCategory($client, '!<script>Parent-'.$token.'</script>', 'wcp529-parent-'.$token);
            $parentId = $parent->getId();
            if ($parentId === null) {
                throw new \LogicException('Persisted parent category fixture has no database ID.');
            }
            $categoryIds[] = $parentId;

            $childSlug = 'wcp529-child-'.$token;
            $child = $this->createCategory($client, 'Child '.$token, $childSlug, $parent);
            $childId = $child->getId();
            if ($childId === null) {
                throw new \LogicException('Persisted child category fixture has no database ID.');
            }
            $categoryIds[] = $childId;
            $entryIds[] = $this->createNews($client, $child, $author, 'visible-'.$token, 'Visible news '.$token);

            $editor = $this->createUser(
                $client,
                'category-editor-'.$token.'@example.test',
                'Category editor '.$token,
                [CmsPermission::ACCESS, CmsPermission::SETTINGS],
            );
            $editorId = $editor->getId();
            if ($editorId === null) {
                throw new \LogicException('Persisted editor fixture has no database ID.');
            }
            $userIds[] = $editorId;

            $client->loginUser($editor);
            $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();

            $container = $client->getContainer();
            $registry = $container->get(WidgetRegistry::class);
            $definition = $registry->get(PublicContentCategoryWidgetProvider::KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertSame('content', $definition->module);
            self::assertTrue($registry->available(PublicContentCategoryWidgetProvider::KEY));

            $availableKeys = array_map(
                static fn (WidgetDefinition $item): string => $item->key,
                $registry->availableDefinitions(),
            );
            self::assertContains(PublicContentCategoryWidgetProvider::KEY, $availableKeys);

            $schema = $container->get(LayoutValidator::class)->widgetSchema(PublicContentCategoryWidgetProvider::KEY);
            self::assertSame(6, $schema['count']['default']);
            self::assertSame(1, $schema['count']['min']);
            self::assertSame(PublicContentCategoryQuery::MAX_ITEMS, $schema['count']['max']);

            $data = $registry->data(PublicContentCategoryWidgetProvider::KEY, ['count' => 6]);
            $categoryIdsInData = array_map(
                static fn (Category $item): ?int => $item->getId(),
                $data['items'],
            );
            self::assertContains($childId, $categoryIdsInData);

            $markup = $container->get(Environment::class)->render($definition->template, [
                'widget' => ['id' => 'content-category-fixture'],
                'config' => ['count' => 6],
                'data' => $data,
            ]);

            self::assertStringContainsString('href="/news/category/'.$childSlug.'"', $markup);
            self::assertStringContainsString('!&lt;script&gt;Parent-'.$token.'&lt;/script&gt; / Child '.$token, $markup);
            self::assertStringNotContainsString('<script>Parent-'.$token.'</script>', $markup);
        } finally {
            $this->removeFixtures($client, $entryIds, $categoryIds, $userIds);
            $this->restoreContentModuleState($client, $moduleSnapshot);
        }
    }

    public function testQueryFiltersInvisibleNewsUsesStableOrderingAndCapsDistinctCategories(): void
    {
        $client = static::createClient();
        $query = $client->getContainer()->get(PublicContentCategoryQuery::class);
        $token = bin2hex(random_bytes(8));
        $categoryIds = [];
        $entryIds = [];
        $userIds = [];
        $expectedCategoryIds = [];

        try {
            $author = $this->createUser($client, 'category-query-'.$token.'@example.test', 'Category query '.$token);
            $authorId = $author->getId();
            if ($authorId === null) {
                throw new \LogicException('Persisted author fixture has no database ID.');
            }
            $userIds[] = $authorId;

            for ($index = 0; $index < 10; ++$index) {
                $suffix = sprintf('%02d', $index);
                $category = $this->createCategory(
                    $client,
                    '!WCP529-'.$token.'-A-'.$suffix,
                    'wcp529-'.$token.'-a-'.$suffix,
                );
                $categoryId = $category->getId();
                if ($categoryId === null) {
                    throw new \LogicException('Persisted category fixture has no database ID.');
                }
                $categoryIds[] = $categoryId;
                $expectedCategoryIds[] = $categoryId;
                $entryIds[] = $this->createNews(
                    $client,
                    $category,
                    $author,
                    'listed-'.$suffix.'-'.$token,
                    'Listed '.$suffix.' '.$token,
                );

                if ($index === 0) {
                    $entryIds[] = $this->createNews(
                        $client,
                        $category,
                        $author,
                        'duplicate-'.$token,
                        'Second listed story '.$token,
                    );
                }
            }

            $sameNameCategoryIds = [];
            for ($index = 0; $index < 5; ++$index) {
                $category = $this->createCategory(
                    $client,
                    '!WCP529-'.$token.'-Z',
                    'wcp529-'.$token.'-z-'.$index,
                );
                $categoryId = $category->getId();
                if ($categoryId === null) {
                    throw new \LogicException('Persisted same-name category fixture has no database ID.');
                }
                $categoryIds[] = $categoryId;
                $sameNameCategoryIds[] = $categoryId;
                $entryIds[] = $this->createNews(
                    $client,
                    $category,
                    $author,
                    'same-name-'.$index.'-'.$token,
                    'Same name news '.$index.' '.$token,
                );
            }

            $pageOnly = $this->createCategory($client, '!WCP529-'.$token.'-0-page', 'wcp529-'.$token.'-page');
            $categoryIds[] = $pageOnly->getId();
            $entryIds[] = $this->createNews(
                $client,
                $pageOnly,
                $author,
                'page-only-'.$token,
                'Page only '.$token,
                type: ContentEntry::TYPE_PAGE,
            );

            $draft = $this->createCategory($client, '!WCP529-'.$token.'-1-draft', 'wcp529-'.$token.'-draft');
            $categoryIds[] = $draft->getId();
            $entryIds[] = $this->createNews(
                $client,
                $draft,
                $author,
                'draft-'.$token,
                'Draft '.$token,
                status: ContentEntry::STATUS_DRAFT,
            );

            $scheduled = $this->createCategory($client, '!WCP529-'.$token.'-2-scheduled', 'wcp529-'.$token.'-scheduled');
            $categoryIds[] = $scheduled->getId();
            $entryIds[] = $this->createNews(
                $client,
                $scheduled,
                $author,
                'scheduled-'.$token,
                'Scheduled '.$token,
                status: ContentEntry::STATUS_SCHEDULED,
            );

            $future = $this->createCategory($client, '!WCP529-'.$token.'-3-future', 'wcp529-'.$token.'-future');
            $categoryIds[] = $future->getId();
            $entryIds[] = $this->createNews(
                $client,
                $future,
                $author,
                'future-'.$token,
                'Future '.$token,
                publishedAt: new \DateTimeImmutable('+1 day'),
            );

            $unlisted = $this->createCategory($client, '!WCP529-'.$token.'-4-unlisted', 'wcp529-'.$token.'-unlisted');
            $categoryIds[] = $unlisted->getId();
            $entryIds[] = $this->createNews(
                $client,
                $unlisted,
                $author,
                'unlisted-'.$token,
                'Unlisted '.$token,
                unlisted: true,
            );

            $expired = $this->createCategory($client, '!WCP529-'.$token.'-5-expired', 'wcp529-'.$token.'-expired');
            $categoryIds[] = $expired->getId();
            $entryIds[] = $this->createNews(
                $client,
                $expired,
                $author,
                'expired-'.$token,
                'Expired '.$token,
                scheduledUnpublishAt: new \DateTimeImmutable('-1 minute'),
            );

            $results = $query->findPublic(99);
            self::assertCount(PublicContentCategoryQuery::MAX_ITEMS, $results);
            self::assertSame(
                [...$expectedCategoryIds, ...array_slice($sameNameCategoryIds, 0, 2)],
                array_map(static fn (Category $category): ?int => $category->getId(), $results),
            );

            $shortPage = $query->findPublic(3);
            self::assertSame(
                array_slice($expectedCategoryIds, 0, 3),
                array_map(static fn (Category $category): ?int => $category->getId(), $shortPage),
            );

            $minimumPage = $query->findPublic(0);
            self::assertCount(1, $minimumPage);
            self::assertSame($expectedCategoryIds[0], $minimumPage[0]->getId());
        } finally {
            $this->removeFixtures($client, $entryIds, $categoryIds, $userIds);
        }
    }

    public function testDisabledContentHidesWidgetAndEmptyDataRendersAnAccessibleState(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureContentModuleState($client);
        $this->setContentModuleEnabled($client, true);
        $token = bin2hex(random_bytes(8));
        $categoryIds = [];
        $entryIds = [];
        $userIds = [];

        try {
            $author = $this->createUser($client, 'category-gate-author-'.$token.'@example.test', 'Category gate author '.$token);
            $authorId = $author->getId();
            if ($authorId === null) {
                throw new \LogicException('Persisted author fixture has no database ID.');
            }
            $userIds[] = $authorId;
            $category = $this->createCategory($client, '!WCP529-gate-'.$token, 'wcp529-gate-'.$token);
            $categoryId = $category->getId();
            if ($categoryId === null) {
                throw new \LogicException('Persisted category fixture has no database ID.');
            }
            $categoryIds[] = $categoryId;
            $entryIds[] = $this->createNews($client, $category, $author, 'gate-'.$token, 'Gate news '.$token);

            $editor = $this->createUser(
                $client,
                'category-gate-editor-'.$token.'@example.test',
                'Category gate editor '.$token,
                [CmsPermission::ACCESS, CmsPermission::SETTINGS],
            );
            $editorId = $editor->getId();
            if ($editorId === null) {
                throw new \LogicException('Persisted editor fixture has no database ID.');
            }
            $userIds[] = $editorId;

            $client->loginUser($editor);
            $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();

            $container = $client->getContainer();
            $registry = $container->get(WidgetRegistry::class);
            self::assertTrue($registry->available(PublicContentCategoryWidgetProvider::KEY));

            $this->setContentModuleEnabled($client, false);
            $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();
            self::assertFalse($registry->available(PublicContentCategoryWidgetProvider::KEY));
            self::assertSame([], $registry->data(PublicContentCategoryWidgetProvider::KEY, ['count' => 6]));

            $definition = $registry->get(PublicContentCategoryWidgetProvider::KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            $emptyMarkup = $container->get(Environment::class)->render($definition->template, [
                'widget' => ['id' => 'empty-category-widget'],
                'config' => [],
                'data' => ['items' => []],
            ]);
            self::assertStringContainsString('Für News sind derzeit keine öffentlichen Kategorien verfügbar.', $emptyMarkup);
            self::assertStringContainsString('role="status"', $emptyMarkup);
        } finally {
            $this->removeFixtures($client, $entryIds, $categoryIds, $userIds);
            $this->restoreContentModuleState($client, $moduleSnapshot);
        }
    }

    private function createCategory(KernelBrowser $client, string $name, string $slug, ?Category $parent = null): Category
    {
        $category = (new Category())
            ->setName($name)
            ->setSlug($slug)
            ->setParent($parent);
        $entityManager = $this->entityManager($client);
        $entityManager->persist($category);
        $entityManager->flush();

        if ($category->getId() === null) {
            throw new \LogicException('Persisted category fixture has no database ID.');
        }

        return $category;
    }

    private function createNews(
        KernelBrowser $client,
        Category $category,
        User $author,
        string $slug,
        string $title,
        string $type = ContentEntry::TYPE_NEWS,
        string $status = ContentEntry::STATUS_PUBLISHED,
        bool $unlisted = false,
        ?\DateTimeImmutable $publishedAt = null,
        ?\DateTimeImmutable $scheduledUnpublishAt = null,
    ): int {
        $entry = (new ContentEntry())
            ->setType($type)
            ->setTitle($title)
            ->setSlug('wcp529-'.$slug)
            ->setAuthor($author)
            ->setCategory($category)
            ->setStatus($status)
            ->setUnlisted($unlisted);

        if ($status === ContentEntry::STATUS_PUBLISHED) {
            $entry->setPublishedAt($publishedAt ?? new \DateTimeImmutable('-1 hour'));
        } elseif ($status === ContentEntry::STATUS_SCHEDULED) {
            $entry->setScheduledAt(new \DateTimeImmutable('+1 day'));
        }
        $entry->setScheduledUnpublishAt($scheduledUnpublishAt);

        $entityManager = $this->entityManager($client);
        $entityManager->persist($entry);
        $entityManager->flush();

        $id = $entry->getId();
        if ($id === null) {
            throw new \LogicException('Persisted content fixture has no database ID.');
        }

        return $id;
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
            ->verifyEmail();

        $entityManager = $this->entityManager($client);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    /**
     * @param list<int> $entryIds
     * @param list<int> $categoryIds
     * @param list<int> $userIds
     */
    private function removeFixtures(KernelBrowser $client, array $entryIds, array $categoryIds, array $userIds): void
    {
        $entityManager = $this->entityManager($client);
        if (!$entityManager->isOpen()) {
            return;
        }

        $entityManager->clear();
        foreach ($entryIds as $id) {
            $entry = $entityManager->find(ContentEntry::class, $id);
            if ($entry instanceof ContentEntry) {
                $entityManager->remove($entry);
            }
        }
        $entityManager->flush();

        $entityManager->clear();
        foreach (array_reverse($categoryIds) as $id) {
            $category = $entityManager->find(Category::class, $id);
            if ($category instanceof Category) {
                $entityManager->remove($category);
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

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
