<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\User;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ContentQualityAuditTest extends WebTestCase
{
    private string $marker;

    private ?Connection $connection = null;

    private bool $contentStateSnapshotTaken = false;

    private bool $contentStateExisted = false;

    private ?bool $previousContentEnabled = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = 'content-quality-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            if ($this->contentStateSnapshotTaken) {
                if ($this->contentStateExisted) {
                    $this->connection->executeStatement(
                        'UPDATE cms_module_state SET enabled = ? WHERE module_key = ?',
                        [$this->previousContentEnabled, 'content'],
                    );
                } else {
                    $this->connection->executeStatement('DELETE FROM cms_module_state WHERE module_key = ?', ['content']);
                }
            }

            $this->connection->executeStatement(
                'DELETE FROM audit_log WHERE actor_id IN (SELECT id FROM cms_user WHERE email LIKE ?)',
                [$this->marker.'-%@example.test'],
            );
            $this->connection->executeStatement('DELETE FROM content_entry WHERE title LIKE ?', [$this->marker.'-%']);
            $this->connection->executeStatement('DELETE FROM cms_user WHERE email LIKE ?', [$this->marker.'-%@example.test']);
        }

        parent::tearDown();
    }

    public function testAnonymousCannotViewQualityReport(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/content/quality');
        self::assertResponseRedirects('/login');
    }

    public function testUserWithoutContentPermissionCannotViewQualityReport(): void
    {
        $client = static::createClient();
        $reader = $this->user($client, [CmsPermission::ACCESS]);
        $client->loginUser($reader);
        $client->request('GET', '/admin/content/quality');
        self::assertResponseStatusCodeSame(403);
    }

    public function testReportSummarizesIssuesAndBoundsPagination(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $em = $this->em($client);
        for ($number = 1; $number <= 52; ++$number) {
            $em->persist($this->entry($manager, sprintf('flagged-%02d', $number)));
        }
        $complete = $this->entry($manager, 'complete', ContentEntry::STATUS_DRAFT, ContentEntry::TYPE_PAGE, true);
        $publishedNoIndex = $this->entry($manager, 'published-noindex', ContentEntry::STATUS_PUBLISHED, ContentEntry::TYPE_NEWS, true, true);
        $trashed = $this->entry($manager, 'trashed', ContentEntry::STATUS_TRASHED);
        foreach ([$complete, $publishedNoIndex, $trashed] as $entry) {
            $em->persist($entry);
        }
        $em->flush();
        $completeId = $this->entryId($complete);
        $trashedId = $this->entryId($trashed);
        $connection = $em->getConnection();
        $expectedContentTotal = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM content_entry WHERE status <> ?',
            [ContentEntry::STATUS_TRASHED],
        );
        $expectedMissingExcerpt = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM content_entry WHERE status <> ? AND (excerpt IS NULL OR excerpt = ?)',
            [ContentEntry::STATUS_TRASHED, ''],
        );
        $expectedPublishedNoIndex = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM content_entry WHERE status = ? AND no_index = TRUE',
            [ContentEntry::STATUS_PUBLISHED],
        );
        $client->loginUser($manager);

        $first = $client->request('GET', '/admin/content/quality?q='.rawurlencode($this->marker));
        self::assertResponseIsSuccessful();
        $this->assertPrivateResponse($client);
        self::assertSame(50, $first->filter('tr[data-content-quality-entry]')->count());
        self::assertSame((string) $expectedMissingExcerpt, trim($first->filter('[data-quality-issue="missing_excerpt"] .summary-value')->text()));
        self::assertSame((string) $expectedPublishedNoIndex, trim($first->filter('[data-quality-issue="published_noindex"] .summary-value')->text()));
        self::assertSelectorTextContains('.panel', $expectedContentTotal.' Inhalte insgesamt');
        $firstIds = $this->rowIds($first);
        self::assertNotContains($completeId, $firstIds);
        self::assertNotContains($trashedId, $firstIds);

        $second = $client->request('GET', '/admin/content/quality?'.http_build_query(['q' => $this->marker, 'page' => 2]));
        self::assertResponseIsSuccessful();
        $this->assertPrivateResponse($client);
        self::assertSame(3, $second->filter('tr[data-content-quality-entry]')->count());
        self::assertSelectorTextContains('.pagination', 'Seite 2 von 2');
        self::assertNotContains($completeId, $this->rowIds($second));
        self::assertNotContains($trashedId, $this->rowIds($second));
    }

    public function testStatusTypeIssueAndTitleFiltersAreReadOnly(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $target = $this->entry($manager, 'needle-target');
        $other = $this->entry($manager, 'other', ContentEntry::STATUS_DRAFT, ContentEntry::TYPE_PAGE, true);
        $published = $this->entry($manager, 'public-noindex', ContentEntry::STATUS_PUBLISHED, ContentEntry::TYPE_PAGE, true, true);
        $em = $this->em($client);
        foreach ([$target, $other, $published] as $entry) {
            $em->persist($entry);
        }
        $em->flush();
        $targetId = $this->entryId($target);
        $publishedId = $this->entryId($published);
        $client->loginUser($manager);
        $before = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM content_entry WHERE title LIKE ?', [$this->marker.'-%']);

        $filtered = $client->request(
            'GET',
            '/admin/content/quality?status=draft&type=news&issue=missing_seo_title&q='.rawurlencode($target->getTitle()),
        );
        self::assertResponseIsSuccessful();
        $this->assertPrivateResponse($client);
        self::assertSame($target->getTitle(), $filtered->filter('input[name="q"]')->attr('value'));
        self::assertSame([$target->getTitle()], $this->rowTitles($filtered));
        self::assertSame([$targetId], $this->rowIds($filtered));

        $publishedView = $client->request(
            'GET',
            '/admin/content/quality?status=published&issue=published_noindex&q='.rawurlencode($this->marker),
        );
        self::assertResponseIsSuccessful();
        self::assertSame([$publishedId], $this->rowIds($publishedView));

        $after = (int) $this->em($client)->getConnection()->fetchOne('SELECT COUNT(*) FROM content_entry WHERE title LIKE ?', [$this->marker.'-%']);
        self::assertSame($before, $after);
        self::assertSame(0, (int) $this->em($client)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE actor_id = ?',
            [$manager->getId()],
        ));
    }

    public function testMalformedFiltersAndMethodBoundary(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $client->loginUser($manager);

        $client->request('GET', '/admin/content/quality?status=unknown');
        self::assertResponseStatusCodeSame(400);
        $this->assertPrivateResponse($client);

        $client->request('GET', '/admin/content/quality?page=01');
        self::assertResponseStatusCodeSame(400);
        $this->assertPrivateResponse($client);

        $client->request('GET', '/admin/content/quality?'.http_build_query(['page' => 2, 'q' => $this->marker]));
        self::assertResponseStatusCodeSame(404);
        $this->assertPrivateResponse($client);

        $client->request('GET', '/admin/content/quality?q%5B%5D=x');
        self::assertResponseStatusCodeSame(400);
        $this->assertPrivateResponse($client);

        $client->request('PUT', '/admin/content/quality');
        self::assertResponseStatusCodeSame(405);
    }

    public function testDisabledContentModuleHidesQualityReport(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $state = $em->getRepository(CmsModuleState::class)->find('content');
        $this->contentStateSnapshotTaken = true;
        $this->contentStateExisted = $state instanceof CmsModuleState;
        $this->previousContentEnabled = $state instanceof CmsModuleState ? $state->isEnabled() : null;
        $state ??= (new CmsModuleState())->setModuleKey('content');
        $state->setEnabled(false);
        $em->persist($state);
        $em->flush();
        self::assertSame(1, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM cms_module_state WHERE module_key = ? AND enabled = FALSE',
            ['content'],
        ));
        $em->clear();

        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $client->loginUser($manager);
        self::assertFalse($client->getContainer()->get(CmsModuleManager::class)->isEnabled('content'));

        $client->request('GET', '/admin/content/quality');
        self::assertSame('app_admin_content_quality_index', $client->getRequest()->attributes->get('_route'));
        self::assertStringContainsString('AdminContentQualityController', (string) $client->getRequest()->attributes->get('_controller'));
        self::assertFalse($client->getContainer()->get(CmsModuleManager::class)->isEnabled('content'));
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param list<string> $permissions
     */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail($this->marker.'-user-'.bin2hex(random_bytes(4)).'@example.test')
            ->setDisplayName('Content quality manager')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function entry(
        User $author,
        string $suffix,
        string $status = ContentEntry::STATUS_DRAFT,
        string $type = ContentEntry::TYPE_NEWS,
        bool $complete = false,
        bool $noIndex = false,
    ): ContentEntry {
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType($type)
            ->setTitle($this->marker.'-'.$suffix)
            ->setSlug($this->marker.'-'.$suffix)
            ->setBody('Readable content body')
            ->setStatus($status)
            ->setNoIndex($noIndex);
        if ($complete) {
            $entry->setExcerpt('A clear summary')
                ->setSeoTitle('Search title')
                ->setSeoDescription('A complete search description')
                ->setCanonicalUrl('https://quality.example.test/'.$suffix);
        }
        if ($status === ContentEntry::STATUS_PUBLISHED) {
            $entry->setPublishedAt(new \DateTimeImmutable('-1 day'));
        }
        $entry->synchronizePublication();

        return $entry;
    }

    /** @return list<int> */
    private function rowIds(Crawler $crawler): array
    {
        $ids = [];
        foreach ($crawler->filter('tr[data-content-quality-entry]') as $row) {
            $ids[] = (int) $row->getAttribute('data-content-quality-entry');
        }

        return $ids;
    }

    /** @return list<string> */
    private function rowTitles(Crawler $crawler): array
    {
        $titles = [];
        foreach ($crawler->filter('tr[data-content-quality-entry] td:first-child') as $cell) {
            $titles[] = trim($cell->textContent);
        }

        return $titles;
    }

    private function entryId(ContentEntry $entry): int
    {
        $id = $entry->getId();
        if ($id === null) {
            throw new \LogicException('A synthetic content entry was not persisted.');
        }

        return $id;
    }

    private function assertPrivateResponse(KernelBrowser $client): void
    {
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow, noarchive', $client->getResponse()->headers->get('X-Robots-Tag'));
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $this->connection = $em->getConnection();

        return $em;
    }
}
