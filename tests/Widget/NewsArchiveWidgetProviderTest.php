<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\User;
use App\Layout\LayoutRenderer;
use App\Layout\LayoutValidator;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use App\Widget\NewsArchiveWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class NewsArchiveWidgetProviderTest extends WebTestCase
{
    private const KEY = NewsArchiveWidgetProvider::KEY;

    public function testPageBuilderOffersTheWidgetAndRendersExistingArchiveRoutes(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->enableContentModule($client);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $userId = null;

        try {
            $user = (new User())
                ->setEmail('news-archive-widget-editor-'.bin2hex(random_bytes(6)).'@example.test')
                ->setDisplayName('News archive widget editor')
                ->setPermissions([CmsPermission::ACCESS, CmsPermission::SETTINGS])
                ->verifyEmail();
            $entityManager->persist($user);
            $entityManager->flush();
            $userId = $user->getId();
            if ($userId === null) {
                throw new \LogicException('Persisted editor fixture has no database ID.');
            }
            $client->loginUser($user);

            $crawler = $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();
            $editor = json_decode(
                (string) $crawler->filter('[data-layout-editor-state-value]')->attr('data-layout-editor-state-value'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertIsArray($editor);
            self::assertIsArray($editor['widgets'] ?? null);
            self::assertContains(self::KEY, array_column($editor['widgets'], 'key'));

            $container = $client->getContainer();
            $registry = $container->get(WidgetRegistry::class);
            $availableKeys = array_map(
                static fn (WidgetDefinition $definition): string => $definition->key,
                $registry->availableDefinitions(),
            );
            self::assertContains(self::KEY, $availableKeys);

            $definition = $registry->get(self::KEY);
            self::assertNotNull($definition);
            self::assertSame('content', $definition->module);
            self::assertSame('widget/news_archive.html.twig', $definition->template);

            $validator = $container->get(LayoutValidator::class);
            $countSchema = $validator->widgetSchema(self::KEY)['count'];
            self::assertSame(6, $countSchema['default']);
            self::assertSame(12, $countSchema['max']);

            $document = $validator->defaults('nebula')->toArray();
            $region = $document['widgets'][0]['region'];
            $document['widgets'][] = [
                'id' => 'news-archive-widget',
                'type' => self::KEY,
                'region' => $region,
                'enabled' => true,
                'config' => ['count' => 3, 'title' => 'Aus dem Archiv'],
            ];
            $layout = $validator->validate($document);
            $view = $container->get(LayoutRenderer::class)->view($layout);
            self::assertSame(self::KEY, $view['regions'][$region][1]['definition']->key);

            $markup = $container->get(Environment::class)->render($definition->template, [
                'widget' => ['id' => 'archive-fixture'],
                'config' => ['title' => 'Aus dem Archiv'],
                'data' => ['periods' => [['year' => 1984, 'month' => 5, 'count' => 2]]],
            ]);
            self::assertStringContainsString('href="/news/archive"', $markup);
            self::assertStringContainsString('href="/news/archive/1984/05"', $markup);
            self::assertStringContainsString('2 Beiträge', $markup);
        } finally {
            if ($userId !== null) {
                $this->removeUserFixture($client, $userId);
            }
            $this->restoreContentModule($client, $moduleSnapshot);
        }
    }

    public function testProviderListsNewestVisiblePeriodsAndRendersSafeEmptyAndPopulatedStates(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $entryIds = [];
        $authorId = null;

        try {
            $author = (new User())
                ->setEmail('news-archive-widget-author-'.bin2hex(random_bytes(6)).'@example.test')
                ->setDisplayName('News archive widget author')
                ->verifyEmail();
            $entityManager->persist($author);
            $entityManager->flush();
            $authorId = $author->getId();

            $year = (int) (new \DateTimeImmutable())->format('Y') - random_int(30, 70);
            if ($year === 1985) {
                ++$year;
            }

            foreach ([
                ['May marker one', 5, 3, ContentEntry::TYPE_NEWS, ContentEntry::STATUS_PUBLISHED, false],
                ['May marker two', 5, 20, ContentEntry::TYPE_NEWS, ContentEntry::STATUS_PUBLISHED, false],
                ['June marker', 6, 10, ContentEntry::TYPE_NEWS, ContentEntry::STATUS_PUBLISHED, false],
                ['July marker one', 7, 2, ContentEntry::TYPE_NEWS, ContentEntry::STATUS_PUBLISHED, false],
                ['July marker two', 7, 19, ContentEntry::TYPE_NEWS, ContentEntry::STATUS_PUBLISHED, false],
                ['Unlisted August marker', 8, 8, ContentEntry::TYPE_NEWS, ContentEntry::STATUS_PUBLISHED, true],
                ['Draft September marker', 9, 9, ContentEntry::TYPE_NEWS, ContentEntry::STATUS_DRAFT, false],
                ['Page October marker', 10, 10, ContentEntry::TYPE_PAGE, ContentEntry::STATUS_PUBLISHED, false],
            ] as [$title, $month, $day, $type, $status, $unlisted]) {
                $entry = $this->createEntry(
                    $entityManager,
                    $author,
                    $title,
                    $type,
                    $status,
                    new \DateTimeImmutable(sprintf('%04d-%02d-%02d 12:00:00', $year, $month, $day)),
                    $unlisted,
                );
                $entryIds[] = $entry->getId();
            }

            $future = $this->createEntry(
                $entityManager,
                $author,
                'Future archive marker',
                ContentEntry::TYPE_NEWS,
                ContentEntry::STATUS_PUBLISHED,
                new \DateTimeImmutable('+1 year'),
            );
            $entryIds[] = $future->getId();

            $provider = $container->get(NewsArchiveWidgetProvider::class);
            $requests = $container->get(RequestStack::class);
            $requests->push(Request::create('/'));
            try {
                $result = $provider->data(self::KEY, ['count' => 12]);
                self::assertArrayHasKey('periods', $result);
                self::assertIsArray($result['periods']);
                /** @var list<array{year:int, month:int, count:int}> $periods */
                $periods = $result['periods'];
                $yearPeriods = array_values(array_filter(
                    $periods,
                    static fn (array $period): bool => $period['year'] === $year,
                ));
                self::assertSame([
                    ['year' => $year, 'month' => 7, 'count' => 2],
                    ['year' => $year, 'month' => 6, 'count' => 1],
                    ['year' => $year, 'month' => 5, 'count' => 2],
                ], $yearPeriods);

                self::assertSame(['periods' => array_slice($periods, 0, 2)], $provider->data(self::KEY, ['count' => 2]));
                self::assertSame(['periods' => array_slice($periods, 0, 1)], $provider->data(self::KEY, ['count' => 0]));
                self::assertSame(['periods' => $periods], $provider->data(self::KEY, ['count' => '2']));
                self::assertSame([], $provider->data('content.unknown', []));

                $definition = $container->get(WidgetRegistry::class)->get(self::KEY);
                self::assertNotNull($definition);
                $markup = $container->get(Environment::class)->render($definition->template, [
                    'widget' => ['id' => 'archive-data-fixture'],
                    'config' => ['title' => '<Archiv>'],
                    'data' => $result,
                ]);
                self::assertStringContainsString('&lt;Archiv&gt;', $markup);
                self::assertStringNotContainsString('<Archiv>', $markup);
                self::assertStringContainsString(sprintf('href="/news/archive/%d/07"', $year), $markup);
                self::assertStringContainsString('2 Beiträge', $markup);
                self::assertStringNotContainsString(sprintf('/news/archive/%d/08', $year), $markup);

                $emptyMarkup = $container->get(Environment::class)->render($definition->template, [
                    'widget' => ['id' => 'archive-empty-fixture'],
                    'config' => [],
                    'data' => ['periods' => []],
                ]);
                self::assertStringContainsString('Noch keine veröffentlichten News im Archiv.', $emptyMarkup);
            } finally {
                $requests->pop();
            }
        } finally {
            $this->cleanup($client, $entryIds, $authorId);
        }
    }

    public function testDisabledContentModuleHidesWidgetAndSuppressesItsData(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->enableContentModule($client);
        $gamingSnapshot = $this->snapshotModuleState($client, 'gaming');

        try {
            $container = $client->getContainer();
            $modules = $container->get(CmsModuleManager::class);
            if ($modules->isEnabled('gaming')) {
                $modules->setEnabled('gaming', false);
            }
            $validator = $container->get(LayoutValidator::class);
            $document = $validator->defaults('nebula')->toArray();
            $region = $document['widgets'][0]['region'];
            $document['widgets'][] = [
                'id' => 'news-archive-disabled',
                'type' => self::KEY,
                'region' => $region,
                'enabled' => true,
                'config' => ['count' => 2],
            ];
            $layout = $validator->validate($document);

            $modules->setEnabled('content', false);
            $registry = $container->get(WidgetRegistry::class);
            self::assertFalse($registry->available(self::KEY));
            self::assertSame([], $registry->data(self::KEY, ['count' => 2]));

            $view = $container->get(LayoutRenderer::class)->view($layout);
            self::assertCount(1, $view['regions'][$region]);
        } finally {
            $this->restoreContentModule($client, $moduleSnapshot);
            $this->restoreModuleState($client, 'gaming', $gamingSnapshot);
        }
    }

    /**
     * @return array{exists: bool, enabled: bool}
     */
    private function snapshotModuleState(KernelBrowser $client, string $key): array
    {
        $state = $client->getContainer()->get(EntityManagerInterface::class)->find(CmsModuleState::class, $key);

        return [
            'exists' => $state instanceof CmsModuleState,
            'enabled' => $state?->isEnabled() ?? true,
        ];
    }

    /**
     * @param array{exists: bool, enabled: bool} $snapshot
     */
    private function restoreModuleState(KernelBrowser $client, string $key, array $snapshot): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        if (!$entityManager->isOpen()) {
            return;
        }
        $entityManager->clear();
        $state = $entityManager->find(CmsModuleState::class, $key);

        if (!$snapshot['exists']) {
            if ($state !== null) {
                $entityManager->remove($state);
            }
        } elseif ($state instanceof CmsModuleState && $state->isEnabled() !== $snapshot['enabled']) {
            $state->setEnabled($snapshot['enabled']);
        }

        $entityManager->flush();
    }

    /**
     * @return array{exists: bool, enabled: bool}
     */
    private function enableContentModule(KernelBrowser $client): array
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $state = $entityManager->find(CmsModuleState::class, 'content');
        $snapshot = [
            'exists' => $state instanceof CmsModuleState,
            'enabled' => $state?->isEnabled() ?? true,
        ];

        $modules = $client->getContainer()->get(CmsModuleManager::class);
        if (!$modules->isInstalled('content')) {
            self::markTestSkipped('The Content module must be installed for the archive widget test.');
        }
        if (!$modules->isEnabled('content')) {
            $modules->setEnabled('content', true);
        }

        return $snapshot;
    }

    /**
     * @param array{exists: bool, enabled: bool} $snapshot
     */
    private function restoreContentModule(KernelBrowser $client, array $snapshot): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        if (!$entityManager->isOpen()) {
            return;
        }
        $entityManager->clear();
        $state = $entityManager->find(CmsModuleState::class, 'content');

        if (!$snapshot['exists']) {
            if ($state !== null) {
                $entityManager->remove($state);
            }
        } elseif ($state instanceof CmsModuleState && $state->isEnabled() !== $snapshot['enabled']) {
            $state->setEnabled($snapshot['enabled']);
        }

        $entityManager->flush();
    }

    private function removeUserFixture(KernelBrowser $client, int $userId): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        if (!$entityManager->isOpen()) {
            return;
        }
        $entityManager->clear();
        $user = $entityManager->find(User::class, $userId);
        if ($user instanceof User) {
            $entityManager->remove($user);
            $entityManager->flush();
        }
    }

    private function createEntry(
        EntityManagerInterface $entityManager,
        User $author,
        string $title,
        string $type,
        string $status,
        ?\DateTimeImmutable $publishedAt,
        bool $unlisted = false,
    ): ContentEntry {
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType($type)
            ->setTitle($title)
            ->setSlug('news-archive-widget-'.bin2hex(random_bytes(8)))
            ->setExcerpt($title)
            ->setBody($title)
            ->setStatus($status)
            ->setPublishedAt($publishedAt)
            ->setUnlisted($unlisted);
        $entityManager->persist($entry);
        $entityManager->flush();

        return $entry;
    }

    /**
     * @param list<int|null> $entryIds
     */
    private function cleanup(KernelBrowser $client, array $entryIds, ?int $authorId): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        if (!$entityManager->isOpen()) {
            return;
        }
        $entityManager->clear();

        foreach ($entryIds as $entryId) {
            if ($entryId === null) {
                continue;
            }

            $entry = $entityManager->find(ContentEntry::class, $entryId);
            if ($entry instanceof ContentEntry) {
                $entityManager->remove($entry);
            }
        }
        $entityManager->flush();

        if ($authorId !== null) {
            $author = $entityManager->find(User::class, $authorId);
            if ($author instanceof User) {
                $entityManager->remove($author);
                $entityManager->flush();
            }
        }
    }
}
