<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\ContentRevision;
use App\Entity\ContentTag;
use App\Entity\User;
use App\Security\CmsPermission;
use App\Service\ContentRevisionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminContentRevisionCompareTest extends WebTestCase
{
    public function testHistoryLinksToEscapedReadOnlyComparisonOfSnapshotAndCurrentContent(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $entry = $this->entry($client, $admin);
        $entityManager = $this->em($client);
        $suffix = bin2hex(random_bytes(5));

        $revisionCategory = (new Category())->setName('Revision category '.$suffix)->setSlug('revision-category-'.$suffix);
        $currentCategory = (new Category())->setName('Current category '.$suffix)->setSlug('current-category-'.$suffix);
        $revisionTag = (new ContentTag())->setName('Revision tag '.$suffix)->setSlug('revision-tag-'.$suffix);
        $currentTag = (new ContentTag())->setName('Current tag '.$suffix)->setSlug('current-tag-'.$suffix);
        foreach ([$revisionCategory, $currentCategory, $revisionTag, $currentTag] as $relatedEntity) {
            $entityManager->persist($relatedEntity);
        }

        $entry
            ->setTitle('Old <script>alert(1)</script>')
            ->setSubtitle('Unchanged subtitle')
            ->setSlug('revision-old-'.$suffix)
            ->setExcerpt('Old excerpt')
            ->setBody('<script>alert(1)</script>')
            ->setCategory($revisionCategory)
            ->addTag($revisionTag);
        $entityManager->flush();

        $revision = $client->getContainer()->get(ContentRevisionManager::class)->capture($entry, $admin);
        $entityManager->flush();
        $revisionId = $revision->getId();
        $entryId = $entry->getId();
        self::assertNotNull($revisionId);
        self::assertNotNull($entryId);

        $entry
            ->setTitle('Current title')
            ->setSlug('revision-current-'.$suffix)
            ->setExcerpt('Current excerpt')
            ->setBody('Current body')
            ->setStatus(ContentEntry::STATUS_REVIEW)
            ->setCategory($currentCategory)
            ->clearTags()
            ->addTag($currentTag);
        $entityManager->flush();

        $updatedAtBeforeRead = $entry->getUpdatedAt()->format(DATE_ATOM);
        $revisionCountBeforeRead = $entityManager->getRepository(ContentRevision::class)->count(['entry' => $entry]);
        $client->loginUser($admin);

        $client->request('GET', '/admin/content/'.$entryId.'/history');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(
            'a[href="/admin/content/'.$entryId.'/revisions/'.$revisionId.'/compare"]',
            'Vergleichen',
        );

        $client->request('GET', '/admin/content/'.$entryId.'/revisions/'.$revisionId.'/compare');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Old &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('Current title', $html);
        self::assertStringContainsString('Unchanged subtitle', $html);
        self::assertStringContainsString('revision-old-'.$suffix, $html);
        self::assertStringContainsString('revision-current-'.$suffix, $html);
        self::assertStringContainsString('Old excerpt', $html);
        self::assertStringContainsString('Current excerpt', $html);
        self::assertStringContainsString('Entwurf', $html);
        self::assertStringContainsString('In Prüfung', $html);
        self::assertStringContainsString('Revision category '.$suffix, $html);
        self::assertStringContainsString('Current category '.$suffix, $html);
        self::assertStringContainsString('revision-tag-'.$suffix, $html);
        self::assertStringContainsString('current-tag-'.$suffix, $html);
        self::assertStringContainsString('Geändert', $html);
        self::assertStringContainsString('Unverändert', $html);
        self::assertStringContainsString('Current body', $html);

        $entityManager->clear();
        $storedEntry = $entityManager->find(ContentEntry::class, $entryId);
        self::assertInstanceOf(ContentEntry::class, $storedEntry);
        self::assertSame('Current title', $storedEntry->getTitle());
        self::assertSame('Current body', $storedEntry->getBody());
        self::assertSame('revision-current-'.$suffix, $storedEntry->getSlug());
        self::assertSame(ContentEntry::STATUS_REVIEW, $storedEntry->getStatus());
        self::assertSame($updatedAtBeforeRead, $storedEntry->getUpdatedAt()->format(DATE_ATOM));
        self::assertSame($revisionCountBeforeRead, $entityManager->getRepository(ContentRevision::class)->count(['entry' => $storedEntry]));
    }

    public function testAnonymousAndUnprivilegedUsersCannotCompareRevisions(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $entry = $this->entry($client, $admin);
        $revision = $client->getContainer()->get(ContentRevisionManager::class)->capture($entry, $admin);
        $this->em($client)->flush();
        $entryId = $entry->getId();
        $revisionId = $revision->getId();
        self::assertNotNull($entryId);
        self::assertNotNull($revisionId);
        $path = '/admin/content/'.$entryId.'/revisions/'.$revisionId.'/compare';

        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(302);

        $unprivileged = $this->user($client, []);
        $client->loginUser($unprivileged);
        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(403);
    }

    public function testMissingAndForeignEntryRevisionsAreNotFound(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $entry = $this->entry($client, $admin);
        $otherEntry = $this->entry($client, $admin);
        $foreignRevision = $client->getContainer()->get(ContentRevisionManager::class)->capture($otherEntry, $admin);
        $this->em($client)->flush();
        $entryId = $entry->getId();
        $foreignRevisionId = $foreignRevision->getId();
        self::assertNotNull($entryId);
        self::assertNotNull($foreignRevisionId);
        $client->loginUser($admin);

        $client->request('GET', '/admin/content/'.$entryId.'/revisions/999999999/compare');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/admin/content/'.$entryId.'/revisions/'.$foreignRevisionId.'/compare');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDisabledContentModuleHidesRevisionComparison(): void
    {
        $client = static::createClient();
        $admin = $this->user($client, [CmsPermission::CONTENT]);
        $entry = $this->entry($client, $admin);
        $revision = $client->getContainer()->get(ContentRevisionManager::class)->capture($entry, $admin);
        $this->em($client)->flush();
        $entryId = $entry->getId();
        $revisionId = $revision->getId();
        self::assertNotNull($entryId);
        self::assertNotNull($revisionId);
        $client->loginUser($admin);

        $entityManager = $this->em($client);
        $existingState = $entityManager->find(CmsModuleState::class, 'content');
        $wasEnabled = $existingState?->isEnabled();
        $state = $existingState ?? (new CmsModuleState())
            ->setModuleKey('content')
            ->updateVersion('1.0.0');
        $state->setEnabled(false);
        $entityManager->persist($state);
        $entityManager->flush();

        try {
            $client->request('GET', '/admin/content/'.$entryId.'/revisions/'.$revisionId.'/compare');
            self::assertResponseStatusCodeSame(404);
        } finally {
            if ($existingState === null) {
                $entityManager->remove($state);
            } else {
                $existingState->setEnabled($wasEnabled ?? true);
            }
            $entityManager->flush();
        }
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('content-revision-compare-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Content revision comparison')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function entry(KernelBrowser $client, User $author): ContentEntry
    {
        $suffix = bin2hex(random_bytes(6));
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Entry '.$suffix)
            ->setSlug('entry-'.$suffix)
            ->setBody('Entry body '.$suffix)
            ->setStatus(ContentEntry::STATUS_DRAFT);
        $this->em($client)->persist($entry);
        $this->em($client)->flush();

        return $entry;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
