<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ContentEntryDeletionSecurityTest extends WebTestCase
{
    public function testTrashRestoreAndPurgeRoutesRequireContentPermission(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, []);
        $draftId = $this->createEntry($client, $user);
        $trashedId = $this->createEntry($client, $user, true);
        $client->loginUser($user);

        $client->request('POST', '/admin/content/'.$draftId.'/trash', ['_token' => 'irrelevant']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/content/'.$trashedId.'/restore-trash', ['_token' => 'irrelevant']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/content/'.$trashedId.'/purge', ['_token' => 'irrelevant']);
        self::assertResponseStatusCodeSame(403);

        $draft = $this->findEntry($client, $draftId);
        $trashed = $this->findEntry($client, $trashedId);
        self::assertInstanceOf(ContentEntry::class, $draft);
        self::assertInstanceOf(ContentEntry::class, $trashed);
        self::assertSame(ContentEntry::STATUS_DRAFT, $draft->getStatus());
        self::assertSame(ContentEntry::STATUS_TRASHED, $trashed->getStatus());
    }

    public function testMissingAndInvalidCsrfTokensCannotMutateTrashLifecycle(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::CONTENT]);
        $draftId = $this->createEntry($client, $user);
        $trashedId = $this->createEntry($client, $user, true);
        $client->loginUser($user);

        $client->request('POST', '/admin/content/'.$draftId.'/trash');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/content/'.$draftId.'/trash', ['_token' => 'invalid-csrf-token']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/content/'.$trashedId.'/restore-trash');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/content/'.$trashedId.'/restore-trash', ['_token' => 'invalid-csrf-token']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/content/'.$trashedId.'/purge');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/content/'.$trashedId.'/purge', ['_token' => 'invalid-csrf-token']);
        self::assertResponseStatusCodeSame(403);

        $draft = $this->findEntry($client, $draftId);
        $trashed = $this->findEntry($client, $trashedId);
        self::assertInstanceOf(ContentEntry::class, $draft);
        self::assertInstanceOf(ContentEntry::class, $trashed);
        self::assertSame(ContentEntry::STATUS_DRAFT, $draft->getStatus());
        self::assertSame(ContentEntry::STATUS_TRASHED, $trashed->getStatus());
    }

    public function testRenderedTrashAndPurgeTokensMoveAndRemoveOnlyTheIntendedEntry(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::CONTENT]);
        $entryId = $this->createEntry($client, $user);
        $untouchedEntryId = $this->createEntry($client, $user);
        $client->loginUser($user);

        $editCrawler = $client->request('GET', '/admin/content/'.$entryId.'/edit');
        self::assertResponseIsSuccessful();
        $trashToken = $this->renderedToken($editCrawler, '/admin/content/'.$entryId.'/trash');

        $client->request('POST', '/admin/content/'.$entryId.'/trash', ['_token' => $trashToken]);

        self::assertResponseRedirects('/admin/content?status=trashed');
        $trashed = $this->findEntry($client, $entryId);
        self::assertInstanceOf(ContentEntry::class, $trashed);
        self::assertSame(ContentEntry::STATUS_TRASHED, $trashed->getStatus());
        self::assertInstanceOf(ContentEntry::class, $this->findEntry($client, $untouchedEntryId));

        $listCrawler = $client->request('GET', '/admin/content?status=trashed');
        self::assertResponseIsSuccessful();
        $purgeToken = $this->renderedToken($listCrawler, '/admin/content/'.$entryId.'/purge');

        $client->request('POST', '/admin/content/'.$entryId.'/purge', ['_token' => $purgeToken]);

        self::assertResponseRedirects('/admin/content?status=trashed');
        self::assertNull($this->findEntry($client, $entryId));
        self::assertInstanceOf(ContentEntry::class, $this->findEntry($client, $untouchedEntryId));
    }

    public function testRenderedRestoreTokenRestoresTrashedEntryAsDraft(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::CONTENT]);
        $entryId = $this->createEntry($client, $user, true);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/content/'.$entryId.'/edit');
        self::assertResponseIsSuccessful();
        $token = $this->renderedToken($crawler, '/admin/content/'.$entryId.'/restore-trash');

        $client->request('POST', '/admin/content/'.$entryId.'/restore-trash', ['_token' => $token]);

        self::assertResponseRedirects('/admin/content/'.$entryId.'/edit');
        $restored = $this->findEntry($client, $entryId);
        self::assertInstanceOf(ContentEntry::class, $restored);
        self::assertSame(ContentEntry::STATUS_DRAFT, $restored->getStatus());
        self::assertSame('Synthetic body '.$entryId, $restored->getBody());
    }

    public function testPreviouslyRenderedPurgeTokenCannotDeleteEntryAfterItLeavesTrash(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::CONTENT]);
        $entryId = $this->createEntry($client, $user, true);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/content?status=trashed');
        self::assertResponseIsSuccessful();
        $token = $this->renderedToken($crawler, '/admin/content/'.$entryId.'/purge');

        $entry = $this->findEntry($client, $entryId);
        self::assertInstanceOf(ContentEntry::class, $entry);
        $entry->restoreFromTrash();
        $client->getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('POST', '/admin/content/'.$entryId.'/purge', ['_token' => $token]);

        self::assertResponseStatusCodeSame(403);
        $stored = $this->findEntry($client, $entryId);
        self::assertInstanceOf(ContentEntry::class, $stored);
        self::assertSame(ContentEntry::STATUS_DRAFT, $stored->getStatus());
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('content-entry-deletion-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Content entry deletion test')
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions)
            ->verifyEmail();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createEntry(KernelBrowser $client, User $author, bool $trashed = false): int
    {
        $suffix = bin2hex(random_bytes(6));
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Content entry '.$suffix)
            ->setSlug('content-entry-'.$suffix)
            ->setBody('Synthetic body for content entry '.$suffix);
        if ($trashed) {
            $entry->trash();
        }
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($entry);
        $entityManager->flush();
        $id = $entry->getId();
        if ($id === null) {
            throw new LogicException('The content-entry fixture must have an identifier.');
        }

        return $id;
    }

    private function findEntry(KernelBrowser $client, int $id): ?ContentEntry
    {
        $entry = $client->getContainer()->get(EntityManagerInterface::class)->find(ContentEntry::class, $id);

        return $entry instanceof ContentEntry ? $entry : null;
    }

    private function renderedToken(Crawler $crawler, string $formAction): string
    {
        $selector = sprintf('form[action="%s"] input[name="_token"]', $formAction);
        $token = $crawler->filter($selector)->attr('value');
        if (!is_string($token) || $token === '') {
            throw new LogicException('The content-entry action form must render its route-specific CSRF token.');
        }

        return $token;
    }
}
