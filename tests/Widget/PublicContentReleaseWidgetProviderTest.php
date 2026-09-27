<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\ContentRelease;
use App\Entity\User;
use App\Layout\LayoutValidator;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use App\Widget\ContentRelease\PublicContentReleaseQuery;
use App\Widget\PublicContentReleaseWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class PublicContentReleaseWidgetProviderTest extends WebTestCase
{
    public function testQueryReturnsOnlyDuePublishedReleasesAndCurrentPublicEntries(): void
    {
        $client = static::createClient();
        $author = $this->createUser($client, 'visibility');
        $token = bin2hex(random_bytes(6));
        $now = new \DateTimeImmutable('+30 days');
        $publishedAt = $now->modify('-2 days');
        $em = $this->entityManager($client);
        $entries = [];
        $releases = [];

        try {
            $news = $this->newEntry($author, 'Visible news', 'news', 'wcp533-'.$token.'-news', $now->modify('-1 hour'));
            $page = $this->newEntry($author, 'Visible page', 'page', 'wcp533-'.$token.'-page', $now->modify('-2 hours'));
            $unpublishesLater = $this->newEntry($author, 'Still visible', 'page', 'wcp533-'.$token.'-later', $now->modify('-3 hours'));
            $draft = $this->newEntry($author, 'Draft entry', 'news', 'wcp533-'.$token.'-draft', $now->modify('-5 minutes'));
            $review = $this->newEntry($author, 'Review entry', 'news', 'wcp533-'.$token.'-review', $now->modify('-6 minutes'));
            $scheduled = $this->newEntry($author, 'Scheduled entry', 'news', 'wcp533-'.$token.'-scheduled', $now->modify('-7 minutes'));
            $archived = $this->newEntry($author, 'Archived entry', 'news', 'wcp533-'.$token.'-archived', $now->modify('-8 minutes'));
            $trashed = $this->newEntry($author, 'Trashed entry', 'news', 'wcp533-'.$token.'-trashed', $now->modify('-9 minutes'));
            $future = $this->newEntry($author, 'Future entry', 'news', 'wcp533-'.$token.'-future', $now->modify('-10 minutes'));
            $unlisted = $this->newEntry($author, 'Unlisted entry', 'news', 'wcp533-'.$token.'-unlisted', $now->modify('-11 minutes'));
            $expired = $this->newEntry($author, 'Expired entry', 'news', 'wcp533-'.$token.'-expired', $now->modify('-12 minutes'));
            $expiresNow = $this->newEntry($author, 'Expires now', 'news', 'wcp533-'.$token.'-expires-now', $now->modify('-13 minutes'));
            $unsupportedType = $this->newEntry($author, 'Unsupported type', 'news', 'wcp533-'.$token.'-unsupported', $now->modify('-14 minutes'));

            $visibleRelease = $this->newRelease(
                $author,
                'Visible release',
                'Visible release description',
                [$news, $page, $unpublishesLater, $draft, $review, $scheduled, $archived, $trashed, $future, $unlisted, $expired, $expiresNow, $unsupportedType],
                $publishedAt,
            );

            $draftReleaseEntry = $this->newEntry($author, 'Draft release entry', 'news', 'wcp533-'.$token.'-draft-release-entry', $now->modify('-1 hour'));
            $draftRelease = $this->newRelease($author, 'Draft release', null, [$draftReleaseEntry], $publishedAt, ContentRelease::STATUS_DRAFT);

            $scheduledReleaseEntry = $this->newEntry($author, 'Scheduled release entry', 'news', 'wcp533-'.$token.'-scheduled-release-entry', $now->modify('-1 hour'));
            $scheduledRelease = $this->newRelease(
                $author,
                'Scheduled release',
                null,
                [$scheduledReleaseEntry],
                $publishedAt,
                ContentRelease::STATUS_SCHEDULED,
                $now->modify('+1 day'),
            );

            $cancelledReleaseEntry = $this->newEntry($author, 'Cancelled release entry', 'news', 'wcp533-'.$token.'-cancelled-release-entry', $now->modify('-1 hour'));
            $cancelledRelease = $this->newRelease($author, 'Cancelled release', null, [$cancelledReleaseEntry], $publishedAt, ContentRelease::STATUS_CANCELLED);

            $futureReleaseEntry = $this->newEntry($author, 'Future release entry', 'news', 'wcp533-'.$token.'-future-release-entry', $now->modify('-1 hour'));
            $futureRelease = $this->newRelease($author, 'Future release', null, [$futureReleaseEntry], $now->modify('+1 day'));
            $futureReleaseEntry->setPublishedAt($now->modify('-1 hour'));

            $noPublicEntry = $this->newEntry($author, 'Only hidden entry', 'news', 'wcp533-'.$token.'-only-hidden', $now->modify('-1 hour'));
            $noPublicRelease = $this->newRelease($author, 'No public entries', null, [$noPublicEntry], $publishedAt);
            $noPublicEntry->setUnlisted(true);

            $draft->setStatus(ContentEntry::STATUS_DRAFT);
            $review->setStatus(ContentEntry::STATUS_REVIEW);
            $scheduled->setStatus(ContentEntry::STATUS_SCHEDULED)->setScheduledAt($now->modify('+1 day'));
            $archived->setStatus(ContentEntry::STATUS_ARCHIVED);
            $trashed->trash();
            $future->setPublishedAt($now->modify('+1 hour'));
            $unlisted->setUnlisted(true);
            $expired->setScheduledUnpublishAt($now->modify('-1 hour'));
            $expiresNow->setScheduledUnpublishAt($now);
            $unpublishesLater->setScheduledUnpublishAt($now->modify('+1 hour'));
            $unsupportedType->setType('video');

            foreach ([$visibleRelease, $draftRelease, $scheduledRelease, $cancelledRelease, $futureRelease, $noPublicRelease] as $release) {
                $this->persistRelease($client, $release, $releases, $entries);
            }
            $em->flush();

            $query = $client->getContainer()->get(PublicContentReleaseQuery::class);
            $result = $query->findPublic(99, $now);

            $fixtureReleaseIds = array_map(
                fn (ContentRelease $release): int => $this->contentReleaseId($release),
                $releases,
            );
            $fixtureResults = array_values(array_filter(
                $result,
                static fn (array $release): bool => in_array($release['id'], $fixtureReleaseIds, true),
            ));

            self::assertCount(1, $fixtureResults);
            self::assertSame($this->contentReleaseId($visibleRelease), $fixtureResults[0]['id']);
            self::assertSame(
                [
                    $this->contentEntryId($news),
                    $this->contentEntryId($page),
                    $this->contentEntryId($unpublishesLater),
                ],
                array_column($fixtureResults[0]['entries'], 'id'),
            );
            self::assertSame('Visible release description', $fixtureResults[0]['description']);
        } finally {
            $this->cleanupFixtures($client, $releases, $entries, $author->getId());
        }
    }

    public function testQueryUsesStableOrderingAndCapsReleasesAndEntries(): void
    {
        $client = static::createClient();
        $author = $this->createUser($client, 'ordering');
        $token = bin2hex(random_bytes(6));
        $now = new \DateTimeImmutable('+30 days');
        $publishedAt = $now->modify('-2 days');
        $em = $this->entityManager($client);
        $entries = [];
        $releases = [];

        try {
            for ($releaseIndex = 0; $releaseIndex < 15; ++$releaseIndex) {
                $releaseEntries = [];
                for ($entryIndex = 0; $entryIndex < 4; ++$entryIndex) {
                    $entry = $this->newEntry(
                        $author,
                        'Tie entry '.$entryIndex,
                        'news',
                        'wcp533-'.$token.'-'.$releaseIndex.'-'.$entryIndex,
                        $publishedAt,
                    );
                    $releaseEntries[] = $entry;
                }

                $release = $this->newRelease($author, 'Tie release', null, $releaseEntries, $publishedAt);
                $this->persistRelease($client, $release, $releases, $entries);
            }
            $em->flush();

            $query = $client->getContainer()->get(PublicContentReleaseQuery::class);
            $results = $query->findPublic(99, $now);
            $expectedReleases = array_slice(array_reverse($releases), 0, PublicContentReleaseQuery::MAX_RELEASES);

            self::assertCount(PublicContentReleaseQuery::MAX_RELEASES, $results);
            self::assertSame(
                array_map(fn (ContentRelease $release): int => $this->contentReleaseId($release), $expectedReleases),
                array_column($results, 'id'),
            );

            foreach ($results as $index => $result) {
                self::assertLessThanOrEqual(PublicContentReleaseQuery::MAX_ENTRIES_PER_RELEASE, count($result['entries']));
                $expectedEntryIds = array_map(
                    fn (ContentEntry $entry): int => $this->contentEntryId($entry),
                    array_slice(array_reverse($expectedReleases[$index]->getEntries()->toArray()), 0, PublicContentReleaseQuery::MAX_ENTRIES_PER_RELEASE),
                );
                self::assertSame($expectedEntryIds, array_column($result['entries'], 'id'));
            }

            self::assertCount(1, $query->findPublic(0, $now));
        } finally {
            $this->cleanupFixtures($client, $releases, $entries, $author->getId());
        }
    }

    public function testWidgetIsAvailableInPageBuilderAndRendersEscapedInternalLinks(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureContentModuleState($client);
        $this->setContentModuleEnabled($client, true);
        $token = bin2hex(random_bytes(6));
        $author = $this->createEditor($client, $token);
        $entryNews = $this->newEntry(
            $author,
            '<script>News '.$token.'</script>',
            'news',
            'wcp533-'.$token.'-news',
            new \DateTimeImmutable('-1 hour'),
            '<img src=x onerror=alert(1)>',
        );
        $entryPage = $this->newEntry(
            $author,
            'Page '.$token,
            'page',
            'wcp533-'.$token.'-page',
            new \DateTimeImmutable('-2 hours'),
        );
        $release = $this->newRelease(
            $author,
            '<script>Release '.$token.'</script>',
            '<img src=x onerror=alert(2)>',
            [$entryNews, $entryPage],
            new \DateTimeImmutable('-1 hour'),
        );
        $releases = [];
        $entries = [];
        $this->persistRelease($client, $release, $releases, $entries);
        $this->entityManager($client)->flush();
        $userId = $author->getId();

        try {
            $client->loginUser($author);
            $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();

            $container = $client->getContainer();
            $registry = $container->get(WidgetRegistry::class);
            $definition = $registry->get(PublicContentReleaseWidgetProvider::KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertSame('content', $definition->module);
            self::assertTrue($registry->available(PublicContentReleaseWidgetProvider::KEY));
            self::assertContains(
                PublicContentReleaseWidgetProvider::KEY,
                array_map(static fn (WidgetDefinition $item): string => $item->key, $registry->availableDefinitions()),
            );

            $schema = $container->get(LayoutValidator::class)->widgetSchema(PublicContentReleaseWidgetProvider::KEY);
            self::assertSame(6, $schema['count']['default']);
            self::assertSame(1, $schema['count']['min']);
            self::assertSame(PublicContentReleaseQuery::MAX_RELEASES, $schema['count']['max']);

            $markup = $container->get(Environment::class)->render($definition->template, [
                'widget' => ['id' => 'release-widget-fixture'],
                'config' => ['count' => 6],
                'data' => $registry->data(PublicContentReleaseWidgetProvider::KEY, ['count' => 6]),
            ]);

            self::assertStringContainsString('href="/news/wcp533-'.$token.'-news"', $markup);
            self::assertStringContainsString('href="/page/wcp533-'.$token.'-page"', $markup);
            self::assertStringContainsString('&lt;script&gt;Release '.$token.'&lt;/script&gt;', $markup);
            self::assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $markup);
            self::assertStringContainsString('&lt;script&gt;News '.$token.'&lt;/script&gt;', $markup);
            self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $markup);
            self::assertStringNotContainsString('<script>Release '.$token.'</script>', $markup);
            self::assertStringNotContainsString('<img src=x onerror=alert(2)>', $markup);

            $emptyMarkup = $container->get(Environment::class)->render($definition->template, [
                'widget' => ['id' => 'release-widget-empty'],
                'config' => [],
                'data' => ['releases' => []],
            ]);
            self::assertStringContainsString('role="status"', $emptyMarkup);
            self::assertStringContainsString('Zurzeit gibt es keine veröffentlichten Content-Releases.', $emptyMarkup);
        } finally {
            $this->cleanupFixtures($client, $releases, $entries, $userId);
            $this->restoreContentModuleState($client, $moduleSnapshot);
        }
    }

    public function testDisabledContentModuleSuppressesWidgetAndItsData(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureContentModuleState($client);
        $this->setContentModuleEnabled($client, true);
        $token = bin2hex(random_bytes(6));
        $author = $this->createEditor($client, $token);
        $entry = $this->newEntry($author, 'Enabled release entry', 'news', 'wcp533-'.$token.'-entry', new \DateTimeImmutable('-1 hour'));
        $release = $this->newRelease($author, 'Enabled release', null, [$entry], new \DateTimeImmutable('-1 hour'));
        $releases = [];
        $entries = [];
        $this->persistRelease($client, $release, $releases, $entries);
        $this->entityManager($client)->flush();
        $userId = $author->getId();

        try {
            $client->loginUser($author);
            $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();
            $registry = $client->getContainer()->get(WidgetRegistry::class);
            self::assertTrue($registry->available(PublicContentReleaseWidgetProvider::KEY));
            $enabledData = $registry->data(PublicContentReleaseWidgetProvider::KEY, ['count' => 6]);
            self::assertIsArray($enabledData['releases']);
            self::assertContains(
                $this->contentReleaseId($release),
                array_column($enabledData['releases'], 'id'),
            );

            $this->setContentModuleEnabled($client, false);
            $client->request('GET', '/admin/layout/home');
            self::assertResponseIsSuccessful();
            self::assertFalse($registry->available(PublicContentReleaseWidgetProvider::KEY));
            self::assertSame([], $registry->data(PublicContentReleaseWidgetProvider::KEY, ['count' => 6]));
        } finally {
            $this->cleanupFixtures($client, $releases, $entries, $userId);
            $this->restoreContentModuleState($client, $moduleSnapshot);
        }
    }

    public function testProviderCacheIsLimitedToTheCurrentRequest(): void
    {
        $client = static::createClient();
        $author = $this->createUser($client, 'cache');
        $token = bin2hex(random_bytes(6));
        $entry = $this->newEntry($author, 'Cached entry', 'news', 'wcp533-'.$token.'-entry', new \DateTimeImmutable('-1 hour'));
        $release = $this->newRelease($author, 'Cached release', null, [$entry], new \DateTimeImmutable('-1 hour'));
        $releases = [];
        $entries = [];
        $this->persistRelease($client, $release, $releases, $entries);
        $this->entityManager($client)->flush();

        try {
            $provider = $client->getContainer()->get(PublicContentReleaseWidgetProvider::class);
            $requestStack = $client->getContainer()->get(RequestStack::class);
            $requestStack->push(Request::create('/'));
            try {
                $first = $provider->data(PublicContentReleaseWidgetProvider::KEY, ['count' => 6]);
                self::assertIsArray($first['releases']);
                $firstIds = array_column($first['releases'], 'id');
                self::assertContains($this->contentReleaseId($release), $firstIds);

                $release->setStatus(ContentRelease::STATUS_CANCELLED);
                $this->entityManager($client)->flush();
                $cached = $provider->data(PublicContentReleaseWidgetProvider::KEY, ['count' => 6]);
                self::assertIsArray($cached['releases']);
                self::assertSame($firstIds, array_column($cached['releases'], 'id'));
            } finally {
                $requestStack->pop();
            }

            $requestStack->push(Request::create('/'));
            try {
                $fresh = $provider->data(PublicContentReleaseWidgetProvider::KEY, ['count' => 6]);
                self::assertIsArray($fresh['releases']);
                self::assertNotContains($this->contentReleaseId($release), array_column($fresh['releases'], 'id'));
            } finally {
                $requestStack->pop();
            }
        } finally {
            $this->cleanupFixtures($client, $releases, $entries, $author->getId());
        }
    }

    private function createUser(KernelBrowser $client, string $suffix): User
    {
        $user = (new User())
            ->setEmail('wcp533-'.$suffix.'-'.bin2hex(random_bytes(4)).'@example.test')
            ->setDisplayName('WCP-533 '.$suffix)
            ->setPassword('unused-test-hash')
            ->verifyEmail();

        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function createEditor(KernelBrowser $client, string $suffix): User
    {
        $user = (new User())
            ->setEmail('wcp533-editor-'.$suffix.'@example.test')
            ->setDisplayName('WCP-533 editor '.$suffix)
            ->setPassword('unused-test-hash')
            ->setPermissions([CmsPermission::ACCESS, CmsPermission::SETTINGS])
            ->verifyEmail();

        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function newEntry(
        User $author,
        string $title,
        string $type,
        string $slug,
        \DateTimeImmutable $publishedAt,
        ?string $excerpt = null,
    ): ContentEntry {
        return (new ContentEntry())
            ->setType($type)
            ->setTitle($title)
            ->setSlug($slug)
            ->setExcerpt($excerpt)
            ->setBody('WCP-533 test content')
            ->setAuthor($author)
            ->setStatus(ContentEntry::STATUS_PUBLISHED)
            ->setPublishedAt($publishedAt);
    }

    /**
     * @param list<ContentEntry> $entries
     */
    private function newRelease(
        User $author,
        string $name,
        ?string $description,
        array $entries,
        \DateTimeImmutable $publishedAt,
        string $status = ContentRelease::STATUS_PUBLISHED,
        ?\DateTimeImmutable $scheduledAt = null,
    ): ContentRelease {
        $release = (new ContentRelease())
            ->setName($name)
            ->setDescription($description)
            ->setCreatedBy($author);
        foreach ($entries as $entry) {
            $release->addEntry($entry);
        }
        $release->publish($publishedAt);
        $release->setStatus($status);
        if ($scheduledAt !== null) {
            $release->setScheduledAt($scheduledAt);
        }

        return $release;
    }

    /**
     * @param list<ContentRelease> $releases
     * @param list<ContentEntry> $allEntries
     */
    private function persistRelease(
        KernelBrowser $client,
        ContentRelease $release,
        array &$releases,
        array &$allEntries,
    ): void {
        $entityManager = $this->entityManager($client);
        foreach ($release->getEntries() as $entry) {
            $entityManager->persist($entry);
            $allEntries[] = $entry;
        }
        $entityManager->persist($release);
        $releases[] = $release;
    }

    /**
     * @param list<ContentRelease> $releases
     * @param list<ContentEntry> $entries
     */
    private function cleanupFixtures(KernelBrowser $client, array $releases, array $entries, ?int $userId): void
    {
        $entityManager = $this->entityManager($client);
        if (!$entityManager->isOpen()) {
            return;
        }

        $entityManager->clear();
        foreach ($releases as $releaseFixture) {
            $releaseId = $releaseFixture->getId();
            if ($releaseId === null) {
                continue;
            }
            $release = $entityManager->find(ContentRelease::class, $releaseId);
            if ($release instanceof ContentRelease) {
                foreach ($release->getEntries() as $entry) {
                    $release->removeEntry($entry);
                }
                $entityManager->remove($release);
            }
        }
        $entityManager->flush();
        $entityManager->clear();

        foreach ($entries as $entryFixture) {
            $entryId = $entryFixture->getId();
            if ($entryId === null) {
                continue;
            }
            $entry = $entityManager->find(ContentEntry::class, $entryId);
            if ($entry instanceof ContentEntry) {
                $entityManager->remove($entry);
            }
        }
        $entityManager->flush();

        if ($userId !== null) {
            $entityManager->clear();
            $user = $entityManager->find(User::class, $userId);
            if ($user instanceof User) {
                $entityManager->remove($user);
                $entityManager->flush();
            }
        }
    }

    /**
     * @return array{exists:bool,enabled:bool}
     */
    private function captureContentModuleState(KernelBrowser $client): array
    {
        if (!$client->getContainer()->get(CmsModuleManager::class)->isInstalled('content')) {
            self::markTestSkipped('The Content module must be installed for the Content Releases widget test.');
        }

        $state = $this->entityManager($client)->find(CmsModuleState::class, 'content');

        return ['exists' => $state instanceof CmsModuleState, 'enabled' => $state?->isEnabled() ?? true];
    }

    private function setContentModuleEnabled(KernelBrowser $client, bool $enabled): void
    {
        $entityManager = $this->entityManager($client);
        $state = $entityManager->find(CmsModuleState::class, 'content');
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())
                ->setModuleKey('content')
                ->updateVersion('test');
            $entityManager->persist($state);
        }
        $state->setEnabled($enabled);
        $entityManager->flush();
    }

    /**
     * @param array{exists:bool,enabled:bool} $snapshot
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

    private function contentReleaseId(ContentRelease $release): int
    {
        $id = $release->getId();
        if ($id === null) {
            throw new \LogicException('Persisted Content Release fixture has no database ID.');
        }

        return $id;
    }

    private function contentEntryId(ContentEntry $entry): int
    {
        $id = $entry->getId();
        if ($id === null) {
            throw new \LogicException('Persisted Content Entry fixture has no database ID.');
        }

        return $id;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}