<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminCategoryContentMoveTest extends WebTestCase
{
    private const AUDIT_ACTION = 'content_category.entries_reassigned';

    public function testCategoryEditorLinksToTransactionalContentMoveAndPreservesEntries(): void
    {
        $client = static::createClient();
        $manager = $this->createUser($client, 'move-success', [CmsPermission::CONTENT]);
        $suffix = bin2hex(random_bytes(5));
        $source = (new Category())->setName('Source '.$suffix)->setSlug('source-'.$suffix);
        $target = (new Category())->setName('Target '.$suffix)->setSlug('target-'.$suffix);
        $this->entityManager($client)->persist($source);
        $this->entityManager($client)->persist($target);
        $draft = $this->createEntry($client, $manager, $source, 'Draft '.$suffix, 'draft-'.$suffix, ContentEntry::STATUS_DRAFT);
        $published = $this->createEntry($client, $manager, $source, 'Published '.$suffix, 'published-'.$suffix, ContentEntry::STATUS_PUBLISHED);
        $this->entityManager($client)->flush();

        $sourceId = $this->requireId($source->getId());
        $targetId = $this->requireId($target->getId());
        $entryIds = [$this->requireId($draft->getId()), $this->requireId($published->getId())];
        $managerId = $this->requireId($manager->getId());
        $editPath = '/admin/categories/'.$sourceId.'/edit';
        $movePath = '/admin/categories/'.$sourceId.'/move-content';
        $client->loginUser($manager);

        try {
            $crawler = $client->request('GET', $editPath);
            self::assertResponseIsSuccessful();
            $moveLink = $crawler->filter('a[href="'.$movePath.'"]');
            self::assertCount(1, $moveLink);
            self::assertSame('Inhalte verschieben', trim($moveLink->text()));

            $crawler = $client->click($moveLink->link());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Inhalte aus „'.$source->getName().'“ verschieben');
            self::assertSelectorTextContains('body', 'Die Kategorie enthält 2 Content-Einträge.');
            self::assertSame(1, $crawler->filter('select[name$="[targetCategory]"] option[value="'.$targetId.'"]')->count());
            self::assertSame(0, $crawler->filter('select[name$="[targetCategory]"] option[value="'.$sourceId.'"]')->count());

            $values = $this->renderedMoveValues($client, $movePath, $targetId, 'valid');
            $client->request('POST', $movePath, $values);
            self::assertResponseRedirects('/admin/categories');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', '2 Inhalte wurden in die gewählte Kategorie verschoben.');

            $entityManager = $this->entityManager($client);
            $entityManager->clear();
            foreach ($entryIds as $entryId) {
                $entry = $entityManager->find(ContentEntry::class, $entryId);
                self::assertInstanceOf(ContentEntry::class, $entry);
                self::assertSame($targetId, $entry->getCategory()?->getId());
            }

            $storedDraft = $entityManager->find(ContentEntry::class, $entryIds[0]);
            $storedPublished = $entityManager->find(ContentEntry::class, $entryIds[1]);
            self::assertInstanceOf(ContentEntry::class, $storedDraft);
            self::assertInstanceOf(ContentEntry::class, $storedPublished);
            self::assertSame(ContentEntry::STATUS_DRAFT, $storedDraft->getStatus());
            self::assertSame(ContentEntry::STATUS_PUBLISHED, $storedPublished->getStatus());
            self::assertSame('Draft '.$suffix, $storedDraft->getTitle());
            self::assertSame('Published '.$suffix, $storedPublished->getTitle());

            $logs = $entityManager->getRepository(AuditLog::class)->findBy([
                'action' => self::AUDIT_ACTION,
                'subjectId' => $sourceId,
            ]);
            self::assertCount(1, $logs);
            self::assertSame($managerId, $logs[0]->getActor()?->getId());
            self::assertSame($targetId, $logs[0]->getContext()['targetCategoryId'] ?? null);
            self::assertSame(2, $logs[0]->getContext()['movedCount'] ?? null);
        } finally {
            $this->cleanup($client, $managerId, [$sourceId, $targetId]);
        }
    }

    public function testNoContentCategoryShowsAnExplicitNoOp(): void
    {
        $client = static::createClient();
        $manager = $this->createUser($client, 'move-empty', [CmsPermission::CONTENT]);
        $suffix = bin2hex(random_bytes(5));
        $source = (new Category())->setName('Empty '.$suffix)->setSlug('empty-'.$suffix);
        $target = (new Category())->setName('Other '.$suffix)->setSlug('other-'.$suffix);
        $this->entityManager($client)->persist($source);
        $this->entityManager($client)->persist($target);
        $this->entityManager($client)->flush();

        $sourceId = $this->requireId($source->getId());
        $targetId = $this->requireId($target->getId());
        $managerId = $this->requireId($manager->getId());
        $client->loginUser($manager);

        try {
            $edit = $client->request('GET', '/admin/categories/'.$sourceId.'/edit');
            $link = $edit->filter('a[href="/admin/categories/'.$sourceId.'/move-content"]');
            self::assertCount(1, $link);
            $client->click($link->link());

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', 'Diese Kategorie enthält keine Inhalte, die verschoben werden müssen.');
            self::assertSelectorNotExists('button[type="submit"]');
            self::assertInstanceOf(Category::class, $this->entityManager($client)->getRepository(Category::class)->find($sourceId));
            self::assertInstanceOf(Category::class, $this->entityManager($client)->getRepository(Category::class)->find($targetId));
            self::assertCount(0, $this->auditLogs($client, $sourceId));
        } finally {
            $this->cleanup($client, $managerId, [$sourceId, $targetId]);
        }
    }

    public function testAuthorizationCsrfAndDestinationValidationPreserveAllEntries(): void
    {
        $client = static::createClient();
        $reader = $this->createUser($client, 'move-denied', []);
        $manager = $this->createUser($client, 'move-invalid', [CmsPermission::CONTENT]);
        $suffix = bin2hex(random_bytes(5));
        $source = (new Category())->setName('Source '.$suffix)->setSlug('source-invalid-'.$suffix);
        $target = (new Category())->setName('Target '.$suffix)->setSlug('target-invalid-'.$suffix);
        $this->entityManager($client)->persist($source);
        $this->entityManager($client)->persist($target);
        $entry = $this->createEntry($client, $manager, $source, 'Unchanged '.$suffix, 'unchanged-'.$suffix, ContentEntry::STATUS_DRAFT);
        $this->entityManager($client)->flush();

        $sourceId = $this->requireId($source->getId());
        $targetId = $this->requireId($target->getId());
        $entryId = $this->requireId($entry->getId());
        $readerId = $this->requireId($reader->getId());
        $managerId = $this->requireId($manager->getId());
        $path = '/admin/categories/'.$sourceId.'/move-content';

        try {
            $client->request('GET', $path);
            self::assertResponseRedirects('/login');

            $client->loginUser($reader);
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', $path, [
                'category_content_move' => ['targetCategory' => (string) $targetId, '_token' => 'invalid'],
            ]);
            self::assertResponseStatusCodeSame(403);
            $this->assertEntryStillInCategory($client, $entryId, $sourceId);

            $client->loginUser($manager);
            foreach ([
                ['mode' => 'missing', 'target' => $targetId],
                ['mode' => 'invalid', 'target' => $targetId],
                ['mode' => 'valid', 'target' => $sourceId],
                ['mode' => 'valid', 'target' => 999999999],
                ['mode' => 'valid', 'target' => ''],
            ] as $case) {
                $values = $this->renderedMoveValues($client, $path, $case['target'], $case['mode']);
                $client->request('POST', $path, $values);
                self::assertResponseStatusCodeSame(422);
                $this->assertEntryStillInCategory($client, $entryId, $sourceId);
                self::assertCount(0, $this->auditLogs($client, $sourceId));
            }
        } finally {
            $this->cleanup($client, $managerId, [$sourceId, $targetId], [$readerId]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function renderedMoveValues(KernelBrowser $client, string $path, int|string $targetId, string $csrfMode): array
    {
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Inhalte verschieben')->form();
        $formName = $form->getName();
        self::assertNotSame('', $formName);

        $values = $form->getPhpValues();
        $formValues = $values[$formName] ?? null;
        self::assertIsArray($formValues);
        self::assertArrayHasKey('_token', $formValues);
        $formValues['targetCategory'] = (string) $targetId;

        if ($csrfMode === 'missing') {
            unset($formValues['_token']);
        } elseif ($csrfMode === 'invalid') {
            $formValues['_token'] = 'invalid';
        }

        $values[$formName] = $formValues;

        return $values;
    }

    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('category-move-'.$label.'-'.bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName('Category move '.$label)
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions);
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function createEntry(
        KernelBrowser $client,
        User $author,
        Category $category,
        string $title,
        string $slug,
        string $status,
    ): ContentEntry {
        $entry = (new ContentEntry())
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle($title)
            ->setSlug($slug)
            ->setSubtitle('Metadata '.$slug)
            ->setBody('Body '.$slug)
            ->setAuthor($author)
            ->setCategory($category)
            ->setStatus($status);

        if ($status === ContentEntry::STATUS_PUBLISHED) {
            $entry->setPublishedAt(new \DateTimeImmutable('-1 day'));
        }

        $this->entityManager($client)->persist($entry);

        return $entry;
    }

    private function assertEntryStillInCategory(KernelBrowser $client, int $entryId, int $categoryId): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $entry = $entityManager->find(ContentEntry::class, $entryId);
        self::assertInstanceOf(ContentEntry::class, $entry);
        self::assertSame($categoryId, $entry->getCategory()?->getId());
    }

    /** @return list<AuditLog> */
    private function auditLogs(KernelBrowser $client, int $sourceId): array
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        return $entityManager->getRepository(AuditLog::class)->findBy([
            'action' => self::AUDIT_ACTION,
            'subjectId' => $sourceId,
        ]);
    }

    /**
     * @param list<int> $categoryIds
     * @param list<int> $additionalUserIds
     */
    private function cleanup(KernelBrowser $client, int $managerId, array $categoryIds, array $additionalUserIds = []): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        foreach ($categoryIds as $categoryId) {
            foreach ($entityManager->getRepository(AuditLog::class)->findBy([
                'action' => self::AUDIT_ACTION,
                'subjectId' => $categoryId,
            ]) as $log) {
                $entityManager->remove($log);
            }
        }

        $manager = $entityManager->find(User::class, $managerId);
        if ($manager instanceof User) {
            foreach ($entityManager->getRepository(ContentEntry::class)->findBy(['author' => $manager]) as $entry) {
                $entityManager->remove($entry);
            }
        }

        foreach ($categoryIds as $categoryId) {
            $category = $entityManager->find(Category::class, $categoryId);
            if ($category instanceof Category) {
                $entityManager->remove($category);
            }
        }

        foreach (array_unique([$managerId, ...$additionalUserIds]) as $userId) {
            $user = $entityManager->find(User::class, $userId);
            if ($user instanceof User) {
                $entityManager->remove($user);
            }
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    private function requireId(?int $id): int
    {
        if ($id === null) {
            throw new LogicException('Persisted fixture has no identifier.');
        }

        return $id;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
