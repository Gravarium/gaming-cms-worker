<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Category;
use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ContentTransferWorkflowTest extends WebTestCase
{
    private string $marker;

    private ?Connection $connection = null;

    private bool $contentStateSnapshotTaken = false;

    private bool $contentStateExisted = false;

    private ?bool $previousContentEnabled = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = 'content-transfer-'.bin2hex(random_bytes(6));
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
            $this->connection->executeStatement(
                'DELETE FROM content_entry_tag WHERE entry_id IN (SELECT id FROM content_entry WHERE title LIKE ?)',
                [$this->marker.'-%'],
            );
            $this->connection->executeStatement('DELETE FROM content_entry WHERE title LIKE ?', [$this->marker.'-%']);
            $this->connection->executeStatement('DELETE FROM content_category WHERE slug LIKE ?', [$this->marker.'-%']);
            $this->connection->executeStatement('DELETE FROM content_tag WHERE slug LIKE ?', [$this->marker.'-%']);
            $this->connection->executeStatement('DELETE FROM cms_user WHERE email LIKE ?', [$this->marker.'-%@example.test']);
        }

        parent::tearDown();
    }

    public function testIndexIsPrivateAndRequiresCmsContentPermission(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/content/transfer');
        self::assertResponseRedirects('/login');

        $client = static::createClient();
        $reader = $this->user($client, [CmsPermission::ACCESS]);
        $client->loginUser($reader);
        $client->request('GET', '/admin/content/transfer');
        self::assertResponseStatusCodeSame(403);

        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $client->loginUser($manager);
        $client->request('GET', '/admin/content/transfer');
        self::assertResponseIsSuccessful();
        $this->assertPrivateResponse($client);
        self::assertSelectorTextContains('h1', 'Content übertragen');
        self::assertSelectorExists('form[name="content_transfer"]');
    }

    public function testExportContainsOnlyPortableContentAndRecordsAudit(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        [$category, $tag] = $this->taxonomy($client);
        $entry = $this->entry($manager, 'exported', 'portable-article', $category, $tag);
        $em = $this->em($client);
        $em->persist($entry);
        $em->flush();
        $entryId = $this->entryId($entry);
        $client->loginUser($manager);

        $crawler = $client->request('GET', '/admin/content/transfer');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/content/transfer/export', [
            '_token' => $token,
            'ids' => [(string) $entryId],
        ]);

        self::assertResponseIsSuccessful();
        $this->assertPrivateResponse($client);
        self::assertSame('application/json; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));

        $bundle = json_decode((string) $client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('gaming-cms-content', $bundle['format']);
        self::assertSame(1, $bundle['version']);
        self::assertCount(1, $bundle['entries']);
        self::assertSame([
            'type', 'title', 'subtitle', 'slug', 'excerpt', 'body', 'categorySlug',
            'tagSlugs', 'seoTitle', 'seoDescription', 'noIndex',
        ], array_keys($bundle['entries'][0]));
        self::assertSame($this->marker.'-exported', $bundle['entries'][0]['title']);
        self::assertSame('portable-article', $bundle['entries'][0]['slug']);
        self::assertSame($this->marker.'-category', $bundle['entries'][0]['categorySlug']);
        self::assertSame([$this->marker.'-tag'], $bundle['entries'][0]['tagSlugs']);
        self::assertSame('Readable body exported', $bundle['entries'][0]['body']);
        self::assertSame(true, $bundle['entries'][0]['noIndex']);
        self::assertArrayNotHasKey('id', $bundle['entries'][0]);
        self::assertArrayNotHasKey('status', $bundle['entries'][0]);
        self::assertArrayNotHasKey('author', $bundle['entries'][0]);
        self::assertArrayNotHasKey('editorDocument', $bundle['entries'][0]);
        self::assertArrayNotHasKey('publishedAt', $bundle['entries'][0]);

        $em = $this->em($client);
        self::assertSame(1, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE actor_id = ? AND action = ?',
            [$manager->getId(), 'content.transfer.export'],
        ));
    }

    public function testImportCreatesDraftsWithCurrentAuthorTaxonomyAndUniqueSlugs(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        [$category, $tag] = $this->taxonomy($client);
        $occupied = $this->entry($manager, 'occupied', 'portable-article');
        $em = $this->em($client);
        $em->persist($occupied);
        $em->flush();
        $client->loginUser($manager);

        $record = $this->record('portable-article', $this->marker.'-category', [$this->marker.'-tag']);
        $payload = $this->bundle([$record, $record]);
        $this->submitImport($client, $payload);

        self::assertResponseRedirects();
        $this->assertPrivateResponse($client);
        $em = $this->em($client);
        $imported = $em->getRepository(ContentEntry::class)->findBy([
            'title' => $this->marker.'-imported title',
        ], ['id' => 'ASC']);
        self::assertCount(2, $imported);
        self::assertSame('portable-article-import-2', $imported[0]->getSlug());
        self::assertSame('portable-article-import-3', $imported[1]->getSlug());

        foreach ($imported as $entry) {
            self::assertSame(ContentEntry::STATUS_DRAFT, $entry->getStatus());
            self::assertSame($manager->getId(), $entry->getAuthor()?->getId());
            self::assertNull($entry->getPublishedAt());
            self::assertNull($entry->getScheduledAt());
            self::assertNull($entry->getScheduledUnpublishAt());
            self::assertFalse($entry->isFeatured());
            self::assertFalse($entry->isPinned());
            self::assertFalse($entry->isUnlisted());
            self::assertNull($entry->getEditorDocument());
            self::assertSame($this->marker.'-category', $entry->getCategory()?->getSlug());
            self::assertSame([$this->marker.'-tag'], array_map(
                static fn (ContentTag $tag): string => $tag->getSlug(),
                $entry->getTags()->toArray(),
            ));
            self::assertSame('Imported SEO title', $entry->getSeoTitle());
        }

        self::assertSame(1, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE actor_id = ? AND action = ?',
            [$manager->getId(), 'content.transfer.import'],
        ));
    }

    public function testInvalidBundleIsRejectedWithoutPartialWrites(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $client->loginUser($manager);

        $first = $this->record('atomic-first');
        $second = $this->record('atomic-second', 'missing-category');
        $payload = $this->bundle([$first, $second]);

        $this->submitImport($client, $payload);
        self::assertResponseStatusCodeSame(422);
        $this->assertPrivateResponse($client);
        self::assertStringNotContainsString($this->marker.'-atomic-first', (string) $client->getResponse()->getContent());

        $em = $this->em($client);
        self::assertSame(0, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM content_entry WHERE slug IN (?, ?)',
            ['atomic-first', 'atomic-second'],
        ));
        self::assertSame(0, (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE actor_id = ? AND action = ?',
            [$manager->getId(), 'content.transfer.import'],
        ));

        $this->submitImport($client, '{"format":"gaming-cms-content","version":2,"entries":[]}');
        self::assertResponseStatusCodeSame(422);
        $this->assertPrivateResponse($client);
    }

    public function testInvalidCsrfSelectionMethodAndDisabledContentModuleAreRejected(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $client->loginUser($manager);

        $client->request('POST', '/admin/content/transfer/export', ['ids' => ['1']]);
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/admin/content/transfer');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/content/transfer/export', ['_token' => $token, 'ids' => []]);
        self::assertResponseStatusCodeSame(400);
        $this->assertPrivateResponse($client);

        $client->request('PUT', '/admin/content/transfer/export');
        self::assertResponseStatusCodeSame(405);

        $em = $this->em($client);
        $state = $em->getRepository(CmsModuleState::class)->find('content');
        $this->contentStateSnapshotTaken = true;
        $this->contentStateExisted = $state instanceof CmsModuleState;
        $this->previousContentEnabled = $state instanceof CmsModuleState ? $state->isEnabled() : null;
        $state ??= (new CmsModuleState())->setModuleKey('content');
        $state->setEnabled(false);
        $em->persist($state);
        $em->flush();

        $client->request('GET', '/admin/content/transfer');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param list<string> $permissions
     */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail($this->marker.'-user-'.bin2hex(random_bytes(4)).'@example.test')
            ->setDisplayName('Content transfer manager')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    /** @return array{Category, ContentTag} */
    private function taxonomy(KernelBrowser $client): array
    {
        $category = (new Category())
            ->setName($this->marker.' category')
            ->setSlug($this->marker.'-category');
        $tag = (new ContentTag())
            ->setName($this->marker.' tag')
            ->setSlug($this->marker.'-tag');
        $em = $this->em($client);
        $em->persist($category);
        $em->persist($tag);
        $em->flush();

        return [$category, $tag];
    }

    private function entry(
        User $author,
        string $suffix,
        string $slug,
        ?Category $category = null,
        ?ContentTag $tag = null,
    ): ContentEntry {
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle($this->marker.'-'.$suffix)
            ->setSlug($slug)
            ->setExcerpt('Portable excerpt')
            ->setBody('Readable body exported')
            ->setEditorDocument('{"version":1,"blocks":[{"type":"paragraph","text":"private editor data"}]}')
            ->setSeoTitle('Source SEO title')
            ->setSeoDescription('Source SEO description')
            ->setNoIndex(true)
            ->setCategory($category);
        if ($tag !== null) {
            $entry->addTag($tag);
        }

        return $entry;
    }

    /** @param list<string> $tagSlugs
     *  @return array<string, mixed>
     */
    private function record(string $slug, ?string $categorySlug = null, array $tagSlugs = []): array
    {
        return [
            'type' => ContentEntry::TYPE_NEWS,
            'title' => $this->marker.'-imported title',
            'subtitle' => null,
            'slug' => $slug,
            'excerpt' => 'Imported excerpt',
            'body' => 'Imported body',
            'categorySlug' => $categorySlug,
            'tagSlugs' => $tagSlugs,
            'seoTitle' => 'Imported SEO title',
            'seoDescription' => 'Imported SEO description',
            'noIndex' => true,
        ];
    }

    /** @param list<array<string, mixed>> $entries */
    private function bundle(array $entries): string
    {
        return json_encode([
            'format' => 'gaming-cms-content',
            'version' => 1,
            'entries' => $entries,
        ], JSON_THROW_ON_ERROR);
    }

    private function submitImport(KernelBrowser $client, string $payload): void
    {
        $crawler = $client->request('GET', '/admin/content/transfer');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="content_transfer[_token]"]')->attr('value');
        $path = tempnam(sys_get_temp_dir(), 'content-transfer-');
        if ($path === false || file_put_contents($path, $payload) === false) {
            throw new \RuntimeException('Unable to prepare temporary content bundle.');
        }

        try {
            $file = new UploadedFile($path, 'content-bundle.json', 'application/json', null, true);
            $client->request(
                'POST',
                '/admin/content/transfer/import',
                ['content_transfer' => ['_token' => $token]],
                ['content_transfer' => ['file' => $file]],
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function assertPrivateResponse(KernelBrowser $client): void
    {
        self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow, noarchive', $client->getResponse()->headers->get('X-Robots-Tag'));
    }

    private function entryId(ContentEntry $entry): int
    {
        $id = $entry->getId();
        if ($id === null) {
            throw new \LogicException('A synthetic content entry was not persisted.');
        }

        return $id;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $this->connection = $em->getConnection();

        return $em;
    }
}
