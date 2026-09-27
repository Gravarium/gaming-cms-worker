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

final class AdminContentBulkMutationSecurityTest extends WebTestCase
{
    public function testBulkRouteRequiresContentPermission(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, []);
        $entryId = $this->createEntry($client, $user);
        $client->loginUser($user);

        $client->request('POST', '/admin/content/bulk', [
            'action' => 'archive',
            'ids' => [$entryId],
        ]);

        self::assertResponseStatusCodeSame(403);
        $entry = $this->findEntry($client, $entryId);
        self::assertInstanceOf(ContentEntry::class, $entry);
        self::assertSame(ContentEntry::STATUS_DRAFT, $entry->getStatus());
    }

    public function testMissingAndInvalidCsrfTokensCannotChangeEntries(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::CONTENT]);
        $entryId = $this->createEntry($client, $user);
        $client->loginUser($user);

        $client->request('POST', '/admin/content/bulk', [
            'action' => 'archive',
            'ids' => [$entryId],
        ]);

        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/content/bulk', [
            'action' => 'archive',
            'ids' => [$entryId],
            '_token' => 'invalid-csrf-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        $entry = $this->findEntry($client, $entryId);
        self::assertInstanceOf(ContentEntry::class, $entry);
        self::assertSame(ContentEntry::STATUS_DRAFT, $entry->getStatus());
    }

    public function testRenderedBulkTokenChangesOnlySelectedEntries(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::CONTENT]);
        $firstId = $this->createEntry($client, $user);
        $secondId = $this->createEntry($client, $user);
        $untouchedId = $this->createEntry($client, $user);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/content');
        self::assertResponseIsSuccessful();
        $token = $this->renderedBulkToken($crawler);

        $client->request('POST', '/admin/content/bulk', [
            '_token' => $token,
            'action' => 'archive',
            'ids' => [$firstId, $secondId],
        ]);

        self::assertResponseRedirects('/admin/content');
        $first = $this->findEntry($client, $firstId);
        $second = $this->findEntry($client, $secondId);
        $untouched = $this->findEntry($client, $untouchedId);
        self::assertInstanceOf(ContentEntry::class, $first);
        self::assertInstanceOf(ContentEntry::class, $second);
        self::assertInstanceOf(ContentEntry::class, $untouched);
        self::assertSame(ContentEntry::STATUS_ARCHIVED, $first->getStatus());
        self::assertSame(ContentEntry::STATUS_ARCHIVED, $second->getStatus());
        self::assertSame(ContentEntry::STATUS_DRAFT, $untouched->getStatus());
    }

    public function testUnknownActionAndMoreThanTwoHundredIdsFailClosed(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::CONTENT]);
        $entryId = $this->createEntry($client, $user);
        $untouchedId = $this->createEntry($client, $user);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/content');
        self::assertResponseIsSuccessful();
        $token = $this->renderedBulkToken($crawler);

        $client->request('POST', '/admin/content/bulk', [
            '_token' => $token,
            'action' => 'delete',
            'ids' => [$entryId],
        ]);

        self::assertResponseStatusCodeSame(404);

        $tooManyIds = array_merge([$entryId], range(1000000, 1000199));
        $client->request('POST', '/admin/content/bulk', [
            '_token' => $token,
            'action' => 'archive',
            'ids' => $tooManyIds,
        ]);

        self::assertResponseStatusCodeSame(403);
        $entry = $this->findEntry($client, $entryId);
        $untouched = $this->findEntry($client, $untouchedId);
        self::assertInstanceOf(ContentEntry::class, $entry);
        self::assertInstanceOf(ContentEntry::class, $untouched);
        self::assertSame(ContentEntry::STATUS_DRAFT, $entry->getStatus());
        self::assertSame(ContentEntry::STATUS_DRAFT, $untouched->getStatus());
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('content-bulk-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Content bulk security test')
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions)
            ->verifyEmail();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createEntry(KernelBrowser $client, User $author): int
    {
        $suffix = bin2hex(random_bytes(6));
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Bulk target '.$suffix)
            ->setSlug('bulk-target-'.$suffix)
            ->setBody('Synthetic bulk content.');
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($entry);
        $entityManager->flush();
        $id = $entry->getId();
        if ($id === null) {
            throw new LogicException('The content bulk fixture must have an identifier.');
        }

        return $id;
    }

    private function findEntry(KernelBrowser $client, int $id): ?ContentEntry
    {
        $entry = $client->getContainer()->get(EntityManagerInterface::class)->find(ContentEntry::class, $id);

        return $entry instanceof ContentEntry ? $entry : null;
    }

    private function renderedBulkToken(Crawler $crawler): string
    {
        $token = $crawler->filter('form[action="/admin/content/bulk"] input[name="_token"]')->attr('value');
        if (!is_string($token) || $token === '') {
            throw new LogicException('The content index must render its bulk-action CSRF token.');
        }

        return $token;
    }
}
