<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminContentTagMergeTest extends WebTestCase
{
    private const AUDIT_ACTION = 'content_tag.merge';

    public function testTagDirectoryLinksToMergeScreenWithOnlyOtherTagsAsTargets(): void
    {
        $client = static::createClient();
        $manager = $this->createUser($client, 'directory', [CmsPermission::CONTENT]);
        $suffix = bin2hex(random_bytes(5));
        $source = $this->createTag('Source '.$suffix, 'source-'.$suffix);
        $target = $this->createTag('Target '.$suffix, 'target-'.$suffix);
        $other = $this->createTag('Other '.$suffix, 'other-'.$suffix);
        $this->entityManager($client)->persist($source);
        $this->entityManager($client)->persist($target);
        $this->entityManager($client)->persist($other);
        $first = $this->createEntry($client, $manager, 'First '.$suffix, 'first-'.$suffix, ContentEntry::STATUS_DRAFT, [$source]);
        $second = $this->createEntry($client, $manager, 'Second '.$suffix, 'second-'.$suffix, ContentEntry::STATUS_ARCHIVED, [$source]);
        $this->entityManager($client)->flush();

        $sourceId = $this->requireId($source->getId());
        $targetId = $this->requireId($target->getId());
        $otherId = $this->requireId($other->getId());
        $managerId = $this->requireId($manager->getId());
        $entrySlugs = ['first-'.$suffix, 'second-'.$suffix];
        $tagSlugs = ['source-'.$suffix, 'target-'.$suffix, 'other-'.$suffix];
        $path = '/admin/content/tags/'.$sourceId.'/merge';
        $client->loginUser($manager);

        try {
            $index = $client->request('GET', '/admin/content/tags');
            self::assertResponseIsSuccessful();
            $link = $index->filter('a[href="'.$path.'"]');
            self::assertCount(1, $link);
            self::assertSame('Zusammenführen', trim($link->text()));

            $crawler = $client->click($link->link());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Content-Tags zusammenführen');
            self::assertSelectorTextContains('[data-entry-count="2"]', '2 Inhalte');
            self::assertSelectorTextContains('body', 'Source '.$suffix);
            self::assertSame(0, $crawler->filter('select[name$="[target]"] option[value="'.$sourceId.'"]')->count());
            self::assertSame(1, $crawler->filter('select[name$="[target]"] option[value="'.$targetId.'"]')->count());
            self::assertSame(1, $crawler->filter('select[name$="[target]"] option[value="'.$otherId.'"]')->count());
        } finally {
            $this->cleanup($client, $managerId, $tagSlugs, $entrySlugs);
        }
    }

    public function testMergeMovesEveryAssociationPreservesEntriesAndAuditsOnce(): void
    {
        $client = static::createClient();
        $manager = $this->createUser($client, 'success', [CmsPermission::CONTENT]);
        $suffix = bin2hex(random_bytes(5));
        $source = $this->createTag('Source '.$suffix, 'source-'.$suffix);
        $target = $this->createTag('Target '.$suffix, 'target-'.$suffix, 'Keep this description.');
        $unrelated = $this->createTag('Unrelated '.$suffix, 'unrelated-'.$suffix);
        $entityManager = $this->entityManager($client);
        $entityManager->persist($source);
        $entityManager->persist($target);
        $entityManager->persist($unrelated);

        $draft = $this->createEntry($client, $manager, 'Draft '.$suffix, 'draft-'.$suffix, ContentEntry::STATUS_DRAFT, [$source, $unrelated]);
        $published = $this->createEntry($client, $manager, 'Published '.$suffix, 'published-'.$suffix, ContentEntry::STATUS_PUBLISHED, [$source, $target, $unrelated]);
        $archived = $this->createEntry($client, $manager, 'Archived '.$suffix, 'archived-'.$suffix, ContentEntry::STATUS_ARCHIVED, [$source]);
        $publishedAt = new \DateTimeImmutable('2025-01-02T03:04:05+00:00');
        $published->setPublishedAt($publishedAt);
        $entityManager->flush();

        $sourceId = $this->requireId($source->getId());
        $targetId = $this->requireId($target->getId());
        $unrelatedId = $this->requireId($unrelated->getId());
        $managerId = $this->requireId($manager->getId());
        $entryIds = [
            $this->requireId($draft->getId()),
            $this->requireId($published->getId()),
            $this->requireId($archived->getId()),
        ];
        $entrySlugs = ['draft-'.$suffix, 'published-'.$suffix, 'archived-'.$suffix];
        $tagSlugs = ['source-'.$suffix, 'target-'.$suffix, 'unrelated-'.$suffix];
        $before = [
            $entryIds[0] => $this->snapshot($draft),
            $entryIds[1] => $this->snapshot($published),
            $entryIds[2] => $this->snapshot($archived),
        ];
        $path = '/admin/content/tags/'.$sourceId.'/merge';
        $client->loginUser($manager);

        try {
            $values = $this->renderedMergeValues($client, $path, $targetId);
            $client->request('POST', $path, $values);

            self::assertResponseRedirects('/admin/content/tags');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', '3 Inhalte wurden dem Ziel-Tag zugeordnet.');

            $entityManager->clear();
            self::assertNull($entityManager->find(ContentTag::class, $sourceId));
            $storedTarget = $entityManager->find(ContentTag::class, $targetId);
            self::assertInstanceOf(ContentTag::class, $storedTarget);
            self::assertSame('Target '.$suffix, $storedTarget->getName());
            self::assertSame('target-'.$suffix, $storedTarget->getSlug());
            self::assertSame('Keep this description.', $storedTarget->getDescription());

            foreach ($entryIds as $entryId) {
                $entry = $entityManager->find(ContentEntry::class, $entryId);
                self::assertInstanceOf(ContentEntry::class, $entry);
                self::assertContains($targetId, array_map(
                    static fn (ContentTag $tag): ?int => $tag->getId(),
                    $entry->getTags()->toArray(),
                ));
                self::assertNotContains($sourceId, array_map(
                    static fn (ContentTag $tag): ?int => $tag->getId(),
                    $entry->getTags()->toArray(),
                ));
                self::assertSame($before[$entryId], $this->snapshot($entry));
            }

            $draftStored = $entityManager->find(ContentEntry::class, $entryIds[0]);
            $publishedStored = $entityManager->find(ContentEntry::class, $entryIds[1]);
            self::assertInstanceOf(ContentEntry::class, $draftStored);
            self::assertInstanceOf(ContentEntry::class, $publishedStored);
            self::assertContains($unrelatedId, array_map(
                static fn (ContentTag $tag): ?int => $tag->getId(),
                $draftStored->getTags()->toArray(),
            ));
            self::assertContains($unrelatedId, array_map(
                static fn (ContentTag $tag): ?int => $tag->getId(),
                $publishedStored->getTags()->toArray(),
            ));
            self::assertSame(ContentEntry::STATUS_DRAFT, $draftStored->getStatus());
            self::assertSame(ContentEntry::STATUS_PUBLISHED, $publishedStored->getStatus());
            self::assertEquals($publishedAt, $publishedStored->getPublishedAt());

            $logs = $entityManager->getRepository(AuditLog::class)->findBy([
                'action' => self::AUDIT_ACTION,
                'subjectId' => $sourceId,
            ]);
            self::assertCount(1, $logs);
            self::assertInstanceOf(AuditLog::class, $logs[0]);
            self::assertSame($managerId, $logs[0]->getActor()?->getId());
            self::assertSame($sourceId, $logs[0]->getContext()['sourceTagId'] ?? null);
            self::assertSame($targetId, $logs[0]->getContext()['targetTagId'] ?? null);
            self::assertSame(3, $logs[0]->getContext()['movedCount'] ?? null);
        } finally {
            $this->cleanup($client, $managerId, $tagSlugs, $entrySlugs);
        }
    }

    public function testMissingInvalidCsrfAndInvalidTargetsDoNotMutateTagsOrEntries(): void
    {
        $client = static::createClient();
        $manager = $this->createUser($client, 'rejected', [CmsPermission::CONTENT]);
        $suffix = bin2hex(random_bytes(5));
        $source = $this->createTag('Source '.$suffix, 'source-'.$suffix);
        $target = $this->createTag('Target '.$suffix, 'target-'.$suffix);
        $entityManager = $this->entityManager($client);
        $entityManager->persist($source);
        $entityManager->persist($target);
        $entry = $this->createEntry($client, $manager, 'Protected '.$suffix, 'protected-'.$suffix, ContentEntry::STATUS_DRAFT, [$source]);
        $entityManager->flush();

        $sourceId = $this->requireId($source->getId());
        $targetId = $this->requireId($target->getId());
        $entryId = $this->requireId($entry->getId());
        $managerId = $this->requireId($manager->getId());
        $path = '/admin/content/tags/'.$sourceId.'/merge';
        $client->loginUser($manager);

        try {
            $cases = [
                ['target' => $targetId, 'csrf' => 'missing'],
                ['target' => $targetId, 'csrf' => 'invalid'],
                ['target' => $sourceId, 'csrf' => 'valid'],
                ['target' => $sourceId + 1000000000, 'csrf' => 'valid'],
            ];

            foreach ($cases as $case) {
                $values = $this->renderedMergeValues($client, $path, $case['target'], $case['csrf']);
                $client->request('POST', $path, $values);

                self::assertResponseStatusCodeSame(422);
                $entityManager->clear();
                self::assertInstanceOf(ContentTag::class, $entityManager->find(ContentTag::class, $sourceId));
                self::assertInstanceOf(ContentTag::class, $entityManager->find(ContentTag::class, $targetId));
                $storedEntry = $entityManager->find(ContentEntry::class, $entryId);
                self::assertInstanceOf(ContentEntry::class, $storedEntry);
                $storedTagIds = array_map(static fn (ContentTag $tag): ?int => $tag->getId(), $storedEntry->getTags()->toArray());
                self::assertContains($sourceId, $storedTagIds);
                self::assertNotContains($targetId, $storedTagIds);
                self::assertCount(0, $this->auditEntries($client, $sourceId));
            }
        } finally {
            $this->cleanup($client, $managerId, ['source-'.$suffix, 'target-'.$suffix], ['protected-'.$suffix]);
        }
    }

    public function testUsersWithoutContentPermissionCannotMergeTags(): void
    {
        $client = static::createClient();
        $reader = $this->createUser($client, 'denied', []);
        $suffix = bin2hex(random_bytes(5));
        $source = $this->createTag('Source '.$suffix, 'source-denied-'.$suffix);
        $target = $this->createTag('Target '.$suffix, 'target-denied-'.$suffix);
        $entityManager = $this->entityManager($client);
        $entityManager->persist($source);
        $entityManager->persist($target);
        $entry = $this->createEntry($client, $reader, 'Protected '.$suffix, 'protected-denied-'.$suffix, ContentEntry::STATUS_DRAFT, [$source]);
        $entityManager->flush();

        $sourceId = $this->requireId($source->getId());
        $targetId = $this->requireId($target->getId());
        $entryId = $this->requireId($entry->getId());
        $readerId = $this->requireId($reader->getId());
        $path = '/admin/content/tags/'.$sourceId.'/merge';
        $client->loginUser($reader);

        try {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', $path, ['content_tag_merge' => ['target' => (string) $targetId]]);
            self::assertResponseStatusCodeSame(403);

            $entityManager->clear();
            self::assertInstanceOf(ContentTag::class, $entityManager->find(ContentTag::class, $sourceId));
            self::assertInstanceOf(ContentTag::class, $entityManager->find(ContentTag::class, $targetId));
            $storedEntry = $entityManager->find(ContentEntry::class, $entryId);
            self::assertInstanceOf(ContentEntry::class, $storedEntry);
            self::assertContains($sourceId, array_map(static fn (ContentTag $tag): ?int => $tag->getId(), $storedEntry->getTags()->toArray()));
            self::assertCount(0, $this->auditEntries($client, $sourceId));
        } finally {
            $this->cleanup($client, $readerId, ['source-denied-'.$suffix, 'target-denied-'.$suffix], ['protected-denied-'.$suffix]);
        }
    }

    public function testEmptySourceTagCanBeMergedAndAuditedWithZeroEntries(): void
    {
        $client = static::createClient();
        $manager = $this->createUser($client, 'empty', [CmsPermission::CONTENT]);
        $suffix = bin2hex(random_bytes(5));
        $source = $this->createTag('Empty '.$suffix, 'empty-'.$suffix);
        $target = $this->createTag('Target '.$suffix, 'empty-target-'.$suffix, 'Target metadata.');
        $entityManager = $this->entityManager($client);
        $entityManager->persist($source);
        $entityManager->persist($target);
        $entityManager->flush();

        $sourceId = $this->requireId($source->getId());
        $targetId = $this->requireId($target->getId());
        $managerId = $this->requireId($manager->getId());
        $path = '/admin/content/tags/'.$sourceId.'/merge';
        $client->loginUser($manager);

        try {
            $values = $this->renderedMergeValues($client, $path, $targetId);
            $client->request('POST', $path, $values);

            self::assertResponseRedirects('/admin/content/tags');
            $entityManager->clear();
            self::assertNull($entityManager->find(ContentTag::class, $sourceId));
            $storedTarget = $entityManager->find(ContentTag::class, $targetId);
            self::assertInstanceOf(ContentTag::class, $storedTarget);
            self::assertSame('Target metadata.', $storedTarget->getDescription());
            $logs = $this->auditEntries($client, $sourceId);
            self::assertCount(1, $logs);
            self::assertSame(0, $logs[0]->getContext()['movedCount'] ?? null);
            self::assertSame($targetId, $logs[0]->getContext()['targetTagId'] ?? null);
        } finally {
            $this->cleanup($client, $managerId, ['empty-'.$suffix, 'empty-target-'.$suffix], []);
        }
    }

    public function testMergeScreenExplainsWhenNoOtherTagCanBeSelected(): void
    {
        $client = static::createClient();
        $manager = $this->createUser($client, 'no-target', [CmsPermission::CONTENT]);
        $suffix = bin2hex(random_bytes(5));
        $source = $this->createTag('Only '.$suffix, 'only-'.$suffix);
        $this->entityManager($client)->persist($source);
        $this->entityManager($client)->flush();

        $sourceId = $this->requireId($source->getId());
        $managerId = $this->requireId($manager->getId());
        $client->loginUser($manager);

        try {
            $crawler = $client->request('GET', '/admin/content/tags/'.$sourceId.'/merge');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', 'Es gibt keinen anderen Tag als Ziel.');
            self::assertCount(0, $crawler->filter('button[type="submit"]'));
            self::assertCount(0, $this->auditEntries($client, $sourceId));
        } finally {
            $this->cleanup($client, $managerId, ['only-'.$suffix], []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function renderedMergeValues(KernelBrowser $client, string $path, int $targetId, string $csrfMode = 'valid'): array
    {
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Zusammenführen')->form();
        $formName = $form->getName();
        self::assertNotSame('', $formName);

        $values = $form->getPhpValues();
        $formValues = $values[$formName] ?? null;
        self::assertIsArray($formValues);
        self::assertArrayHasKey('_token', $formValues);
        self::assertNotSame('', (string) $formValues['_token']);
        $formValues['target'] = (string) $targetId;

        if ($csrfMode === 'missing') {
            unset($formValues['_token']);
        } elseif ($csrfMode === 'invalid') {
            $formValues['_token'] = 'invalid';
        }

        $values[$formName] = $formValues;

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ContentEntry $entry): array
    {
        return [
            'title' => $entry->getTitle(),
            'slug' => $entry->getSlug(),
            'body' => $entry->getBody(),
            'status' => $entry->getStatus(),
            'publishedAt' => $entry->getPublishedAt()?->format(DATE_ATOM),
            'createdAt' => $entry->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $entry->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    private function createTag(string $name, string $slug, ?string $description = null): ContentTag
    {
        return (new ContentTag())
            ->setName($name)
            ->setSlug($slug)
            ->setDescription($description);
    }

    /**
     * @param list<ContentTag> $tags
     */
    private function createEntry(KernelBrowser $client, User $author, string $title, string $slug, string $status, array $tags): ContentEntry
    {
        $entry = (new ContentEntry())
            ->setTitle($title)
            ->setSlug($slug)
            ->setBody('Body for '.$title)
            ->setStatus($status)
            ->setAuthor($author);

        if ($status === ContentEntry::STATUS_PUBLISHED) {
            $entry->setPublishedAt(new \DateTimeImmutable('2025-01-02T03:04:05+00:00'));
        }

        foreach ($tags as $tag) {
            $entry->addTag($tag);
        }

        $this->entityManager($client)->persist($entry);

        return $entry;
    }

    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('content-tag-merge-'.$label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Content tag merge '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    /**
     * @return list<AuditLog>
     */
    private function auditEntries(KernelBrowser $client, int $sourceId): array
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $entries = $entityManager->getRepository(AuditLog::class)->findBy([
            'action' => self::AUDIT_ACTION,
            'subjectId' => $sourceId,
        ]);

        return array_values(array_filter(
            $entries,
            static fn (object $entry): bool => $entry instanceof AuditLog,
        ));
    }

    /**
     * @param list<string> $tagSlugs
     * @param list<string> $entrySlugs
     */
    private function cleanup(KernelBrowser $client, int $userId, array $tagSlugs, array $entrySlugs): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        foreach ($entrySlugs as $slug) {
            $entry = $entityManager->getRepository(ContentEntry::class)->findOneBy(['slug' => $slug]);
            if ($entry instanceof ContentEntry) {
                $entry->clearTags();
                $entityManager->remove($entry);
            }
        }

        foreach ($tagSlugs as $slug) {
            $tag = $entityManager->getRepository(ContentTag::class)->findOneBy(['slug' => $slug]);
            if ($tag instanceof ContentTag) {
                $entityManager->remove($tag);
            }
        }

        $user = $entityManager->find(User::class, $userId);
        if ($user instanceof User) {
            foreach ($entityManager->getRepository(AuditLog::class)->findBy(['actor' => $user]) as $log) {
                $entityManager->remove($log);
            }
            $entityManager->remove($user);
        }

        $entityManager->flush();
        $entityManager->clear();
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
