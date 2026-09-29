<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\ContentEditor\ContentBlockDocument;
use App\Entity\AuditLog;
use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\ContentRevision;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContentAccessibilityAuditTest extends WebTestCase
{
    public function testReportSummarizesFindingsLinksToEditsAndLeavesContentReadOnly(): void
    {
        $client = static::createClient();
        [$user, $entry] = $this->createEntry(
            $client,
            'accessibility-audit-'.bin2hex(random_bytes(5)),
            '<script>Accessibility report fixture</script>',
            'page',
            ContentEntry::STATUS_DRAFT,
            $this->document([
                ['type' => 'heading', 'level' => 3, 'text' => 'Skipped heading'],
                ['type' => 'media', 'assetId' => 987654, 'alt' => ' ', 'caption' => 'Fixture image'],
            ]),
        );
        $entryId = $this->requireId($entry->getId());
        $updatedAt = $entry->getUpdatedAt()->format('U.u');
        $body = $entry->getBody();
        $storedDocument = $entry->getEditorDocument();
        $revisionCount = $this->entityManager($client)->getRepository(ContentRevision::class)->count(['entry' => $entry]);
        $auditCount = $this->countAuditLogs($client, $entryId);

        try {
            $client->loginUser($user);
            $client->request('GET', '/admin/content-accessibility?'.http_build_query([
                'q' => 'Accessibility report fixture',
            ]));

            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $cacheControl = array_map(
                'trim',
                explode(',', strtolower((string) $client->getResponse()->headers->get('Cache-Control'))),
            );
            self::assertContains('private', $cacheControl);
            self::assertContains('no-store', $cacheControl);
            self::assertSelectorTextContains('main', 'Barrierefreiheitsbericht');
            self::assertSelectorTextContains('main', 'Bilder ohne Alternativtext');
            self::assertSelectorTextContains('main', 'Übersprungene Überschriftenstufen');
            self::assertSelectorExists('a[href="/admin/content/'.$entryId.'/edit"]');
            self::assertStringNotContainsString('<script>Accessibility report fixture</script>', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('&lt;script&gt;Accessibility report fixture&lt;/script&gt;', (string) $client->getResponse()->getContent());

            $entityManager = $this->entityManager($client);
            $entityManager->clear();
            $storedEntry = $entityManager->find(ContentEntry::class, $entryId);
            self::assertInstanceOf(ContentEntry::class, $storedEntry);
            self::assertSame($body, $storedEntry->getBody());
            self::assertSame($storedDocument, $storedEntry->getEditorDocument());
            self::assertSame($updatedAt, $storedEntry->getUpdatedAt()->format('U.u'));
            self::assertSame($revisionCount, $entityManager->getRepository(ContentRevision::class)->count(['entry' => $storedEntry]));
            self::assertSame($auditCount, $this->countAuditLogs($client, $entryId));
        } finally {
            $this->cleanup($client, [$entryId], $user);
        }
    }

    public function testStrictFiltersAndDeterministicPagination(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $prefix = 'accessibility-batch-'.$suffix;
        $document = $this->document([
            ['type' => 'media', 'assetId' => 987654, 'alt' => '', 'caption' => ''],
        ]);
        [$user, $seedEntry] = $this->createEntry($client, 'batch-author-'.$suffix, 'Unused seed', 'page', ContentEntry::STATUS_DRAFT, 'seed');
        $entries = [];

        for ($index = 0; $index < 26; ++$index) {
            $type = $index % 2 === 0 ? ContentEntry::TYPE_PAGE : ContentEntry::TYPE_NEWS;
            $status = $index < 4 ? ContentEntry::STATUS_ARCHIVED : ContentEntry::STATUS_DRAFT;
            $entry = $this->makeEntry($user, $prefix.' case '.$index, $type, $status, $document);
            $entries[] = $entry;
        }
        $this->entityManager($client)->flush();
        $entryIds = [$this->requireId($seedEntry->getId()), ...array_map(fn (ContentEntry $entry): int => $this->requireId($entry->getId()), $entries)];

        try {
            $client->loginUser($user);
            $client->request('GET', '/admin/content-accessibility?'.http_build_query([
                'q' => $prefix,
                'issue' => 'missing_media_alt',
                'page' => '2',
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[data-report-summary]', '26 Einträge');
            self::assertSelectorTextContains('[data-report-pagination]', 'Seite 2 von 2');
            self::assertCount(1, $client->getCrawler()->filter('table tbody tr'));

            $client->request('GET', '/admin/content-accessibility?'.http_build_query([
                'q' => $prefix,
                'issue' => 'missing_media_alt',
                'status' => ContentEntry::STATUS_ARCHIVED,
                'type' => ContentEntry::TYPE_PAGE,
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[data-report-summary]', '2 Einträge');
            self::assertCount(2, $client->getCrawler()->filter('table tbody tr'));
        } finally {
            $this->cleanup($client, $entryIds, $user);
        }
    }

    public function testReportStopsAfterFiveHundredCandidatesAndTwentyPages(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(5));
        $prefix = 'accessibility-cap-'.$suffix;
        [$user] = $this->createEntry($client, 'cap-author-'.$suffix, 'Unused seed', 'page', ContentEntry::STATUS_DRAFT, 'seed');
        $document = $this->document([
            ['type' => 'media', 'assetId' => 987654, 'alt' => '', 'caption' => ''],
        ]);
        $entries = [];

        for ($index = 0; $index < 501; ++$index) {
            $entries[] = $this->makeEntry(
                $user,
                $prefix.' case '.$index,
                ContentEntry::TYPE_PAGE,
                ContentEntry::STATUS_DRAFT,
                $document,
            );
        }
        $this->entityManager($client)->flush();
        $entryIds = [$this->requireId($seedEntry->getId()), ...array_map(fn (ContentEntry $entry): int => $this->requireId($entry->getId()), $entries)];

        try {
            $client->loginUser($user);
            $client->request('GET', '/admin/content-accessibility?'.http_build_query([
                'q' => $prefix,
                'issue' => 'missing_media_alt',
                'page' => '20',
            ]));

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[data-report-summary]', '500 Einträge');
            self::assertSelectorTextContains('[data-report-pagination]', 'Seite 20 von 20');
            self::assertSelectorTextContains('[data-scan-limit-warning]', '500 passende Einträge');
            self::assertCount(25, $client->getCrawler()->filter('table tbody tr'));
        } finally {
            $this->cleanup($client, $entryIds, $user);
        }
    }

    public function testAuthorizationModuleGateAndNavigationVisibility(): void
    {
        $client = static::createClient();
        [$user, $seedEntry] = $this->createEntry(
            $client,
            'accessibility-manager-'.bin2hex(random_bytes(5)),
            'Unused seed',
            'page',
            ContentEntry::STATUS_DRAFT,
            'seed',
        );
        $moduleState = $this->snapshotContentModule($client);

        try {
            $client->loginUser($user);
            $this->setContentModuleEnabled($client, false);

            $client->request('GET', '/admin/content-accessibility');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('a[href="/admin/content-accessibility"]');

            $this->setContentModuleEnabled($client, true);
            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/admin/content-accessibility"]');
        } finally {
            $this->cleanup($client, [$this->requireId($seedEntry->getId())], $user);
            $this->restoreContentModule($client, $moduleState);
        }
    }

    public function testAnonymousAndInsufficientPermissionAreDeniedAndBadInputsReturnBadRequest(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/content-accessibility');
        self::assertResponseRedirects('/login');

        $user = (new User())
            ->setEmail('accessibility-reader-'.bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName('Accessibility report reader')
            ->setPermissions([CmsPermission::ACCESS])
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        try {
            $client->loginUser($user);
            $client->request('GET', '/admin/content-accessibility');
            self::assertResponseStatusCodeSame(403);

            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('a[href="/admin/content-accessibility"]');

            $manager = (new User())
                ->setEmail('accessibility-manager-invalid-'.bin2hex(random_bytes(5)).'@example.test')
                ->setDisplayName('Accessibility report manager')
                ->setPermissions([CmsPermission::ACCESS, CmsPermission::CONTENT])
                ->verifyEmail();
            $this->entityManager($client)->persist($manager);
            $this->entityManager($client)->flush();
            $client->loginUser($manager);

            $client->request('GET', '/admin/content-accessibility?q%5B%5D=bad');
            self::assertResponseStatusCodeSame(400);

            $client->request('GET', '/admin/content-accessibility?page=01');
            self::assertResponseStatusCodeSame(400);

            $client->request('POST', '/admin/content-accessibility');
            self::assertResponseStatusCodeSame(405);
        } finally {
            $this->cleanup($client, [], $user);
            if (isset($manager)) {
                $this->cleanup($client, [], $manager);
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function document(array $blocks): string
    {
        return ContentBlockDocument::PREFIX.json_encode(
            ['version' => ContentBlockDocument::VERSION, 'blocks' => $blocks],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array{0:User,1:ContentEntry}
     */
    private function createEntry(
        KernelBrowser $client,
        string $slug,
        string $title,
        string $type,
        string $status,
        string $document,
    ): array {
        $suffix = bin2hex(random_bytes(3));
        $user = (new User())
            ->setEmail('accessibility-author-'.$suffix.'@example.test')
            ->setDisplayName('Accessibility report author')
            ->setPermissions([CmsPermission::ACCESS, CmsPermission::CONTENT])
            ->verifyEmail();
        $entry = $this->makeEntry($user, $title, $type, $status, $document, $slug);

        $entityManager = $this->entityManager($client);
        $entityManager->persist($user);
        $entityManager->persist($entry);
        $entityManager->flush();

        return [$user, $entry];
    }

    private function makeEntry(
        User $author,
        string $title,
        string $type,
        string $status,
        string $document,
        ?string $slug = null,
    ): ContentEntry {
        $slug ??= 'accessibility-'.bin2hex(random_bytes(7));

        return (new ContentEntry())
            ->setAuthor($author)
            ->setType($type)
            ->setTitle($title)
            ->setSlug($slug)
            ->setStatus($status)
            ->setEditableDocument($document);
    }

    /**
     * @param list<int> $entryIds
     */
    private function cleanup(KernelBrowser $client, array $entryIds, User $user): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        foreach ($entryIds as $entryId) {
            $entry = $entityManager->find(ContentEntry::class, $entryId);
            if ($entry instanceof ContentEntry) {
                $entityManager->remove($entry);
            }
        }

        $storedUser = $entityManager->find(User::class, $user->getId());
        if ($storedUser instanceof User) {
            $entityManager->remove($storedUser);
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    private function countAuditLogs(KernelBrowser $client, int $entryId): int
    {
        $count = $this->entityManager($client)->createQueryBuilder()
            ->select('COUNT(log.id)')
            ->from(AuditLog::class, 'log')
            ->andWhere('log.subjectType = :subjectType')
            ->andWhere('log.subjectId = :subjectId')
            ->setParameter('subjectType', ContentEntry::class)
            ->setParameter('subjectId', $entryId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    /**
     * @return array{present:bool,installed:bool,enabled:bool,version:?string}
     */
    private function snapshotContentModule(KernelBrowser $client): array
    {
        $state = $this->entityManager($client)->getRepository(CmsModuleState::class)->find('content');

        return [
            'present' => $state instanceof CmsModuleState,
            'installed' => $state instanceof CmsModuleState && $state->isInstalled(),
            'enabled' => $state instanceof CmsModuleState ? $state->isEnabled() : true,
            'version' => $state instanceof CmsModuleState ? $state->getInstalledVersion() : null,
        ];
    }

    private function setContentModuleEnabled(KernelBrowser $client, bool $enabled): void
    {
        $entityManager = $this->entityManager($client);
        $state = $entityManager->getRepository(CmsModuleState::class)->find('content')
            ?? (new CmsModuleState())->setModuleKey('content')->updateVersion('1.0.0');
        if (!$state->isInstalled()) {
            $state->install($state->getInstalledVersion() ?? '1.0.0');
        }
        $state->setEnabled($enabled);
        $entityManager->persist($state);
        $entityManager->flush();
    }

    /**
     * @param array{present:bool,installed:bool,enabled:bool,version:?string} $snapshot
     */
    private function restoreContentModule(KernelBrowser $client, array $snapshot): void
    {
        $entityManager = $this->entityManager($client);
        $state = $entityManager->getRepository(CmsModuleState::class)->find('content');

        if (!$snapshot['present']) {
            if ($state instanceof CmsModuleState) {
                $entityManager->remove($state);
            }
        } else {
            if (!$state instanceof CmsModuleState) {
                $state = (new CmsModuleState())->setModuleKey('content');
                $entityManager->persist($state);
            }

            if ($snapshot['installed']) {
                if (!$state->isInstalled()) {
                    $state->install($snapshot['version'] ?? '1.0.0');
                }
                $state->setEnabled($snapshot['enabled']);
            } else {
                $state->removePackage();
            }
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    private function requireId(?int $id): int
    {
        if ($id === null) {
            throw new \LogicException('Persisted content entry has no identifier.');
        }

        return $id;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
