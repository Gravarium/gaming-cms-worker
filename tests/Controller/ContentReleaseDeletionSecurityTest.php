<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\ContentRelease;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ContentReleaseDeletionSecurityTest extends WebTestCase
{
    public function testReleaseRoutesRequireContentPermission(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, []);
        $releaseId = $this->createDraftRelease($client, $user);
        $client->loginUser($user);

        $client->request('GET', '/admin/content/releases');

        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/content/releases/'.$releaseId.'/delete', [
            '_token' => 'invalid-csrf-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertInstanceOf(ContentRelease::class, $this->findRelease($client, $releaseId));
    }

    public function testDeleteRejectsInvalidCsrfWithoutRemovingDraftRelease(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::CONTENT]);
        $releaseId = $this->createDraftRelease($client, $user);
        $client->loginUser($user);

        $client->request('POST', '/admin/content/releases/'.$releaseId.'/delete', [
            '_token' => 'invalid-csrf-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        $stored = $this->findRelease($client, $releaseId);
        self::assertInstanceOf(ContentRelease::class, $stored);
        self::assertSame(ContentRelease::STATUS_DRAFT, $stored->getStatus());
    }

    public function testOwnerCanDeleteUnpublishedReleaseWithRenderedCsrfToken(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::CONTENT]);
        $releaseId = $this->createDraftRelease($client, $user);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/content/releases');
        self::assertResponseIsSuccessful();
        $token = $this->renderedDeleteToken($crawler, $releaseId);
        $client->request('POST', '/admin/content/releases/'.$releaseId.'/delete', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin/content/releases');
        self::assertNull($this->findRelease($client, $releaseId));
    }

    public function testPublishedReleaseCannotBeDeletedWithValidCsrfToken(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::CONTENT]);
        [$releaseId, $entryId] = $this->createDraftReleaseWithEntry($client, $user);
        $client->loginUser($user);

        $draftCrawler = $client->request('GET', '/admin/content/releases');
        self::assertResponseIsSuccessful();
        $token = $this->renderedDeleteToken($draftCrawler, $releaseId);

        $release = $this->findRelease($client, $releaseId);
        self::assertInstanceOf(ContentRelease::class, $release);
        $release->publish(new \DateTimeImmutable());
        $client->getContainer()->get(EntityManagerInterface::class)->flush();

        $publishedCrawler = $client->request('GET', '/admin/content/releases');
        self::assertResponseIsSuccessful();
        self::assertSame(
            0,
            $publishedCrawler->filter('form[action="/admin/content/releases/'.$releaseId.'/delete"]')->count(),
        );
        $client->request('POST', '/admin/content/releases/'.$releaseId.'/delete', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
        $storedRelease = $this->findRelease($client, $releaseId);
        $storedEntry = $client->getContainer()->get(EntityManagerInterface::class)->find(ContentEntry::class, $entryId);
        self::assertInstanceOf(ContentRelease::class, $storedRelease);
        self::assertInstanceOf(ContentEntry::class, $storedEntry);
        self::assertSame(ContentRelease::STATUS_PUBLISHED, $storedRelease->getStatus());
        self::assertNotNull($storedRelease->getPublishedAt());
        self::assertSame(ContentEntry::STATUS_PUBLISHED, $storedEntry->getStatus());
        self::assertCount(1, $storedRelease->getEntries());
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('content-release-delete-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Content release deletion test')
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions)
            ->verifyEmail();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createDraftRelease(KernelBrowser $client, User $creator): int
    {
        $release = (new ContentRelease())
            ->setName('Draft release '.bin2hex(random_bytes(6)))
            ->setCreatedBy($creator);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($release);
        $entityManager->flush();
        $id = $release->getId();
        if ($id === null) {
            throw new LogicException('The draft-release fixture must have an identifier.');
        }

        return $id;
    }

    /**
     * @return array{int, int}
     */
    private function createDraftReleaseWithEntry(KernelBrowser $client, User $creator): array
    {
        $suffix = bin2hex(random_bytes(6));
        $entry = (new ContentEntry())
            ->setAuthor($creator)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Published release entry '.$suffix)
            ->setSlug('published-release-entry-'.$suffix)
            ->setBody('Published release test body.');
        $release = (new ContentRelease())
            ->setName('Published release '.$suffix)
            ->setCreatedBy($creator)
            ->addEntry($entry);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($entry);
        $entityManager->persist($release);
        $entityManager->flush();
        $releaseId = $release->getId();
        $entryId = $entry->getId();
        if ($releaseId === null || $entryId === null) {
            throw new LogicException('Published-release fixtures must have identifiers.');
        }

        return [$releaseId, $entryId];
    }

    private function findRelease(KernelBrowser $client, int $id): ?ContentRelease
    {
        $release = $client->getContainer()->get(EntityManagerInterface::class)->find(ContentRelease::class, $id);

        return $release instanceof ContentRelease ? $release : null;
    }

    private function renderedDeleteToken(Crawler $crawler, int $releaseId): string
    {
        $selector = sprintf(
            'form[action="/admin/content/releases/%d/delete"] input[name="_token"]',
            $releaseId,
        );
        $token = $crawler->filter($selector)->attr('value');
        if (!is_string($token) || $token === '') {
            throw new LogicException('The release list must render a delete token for an unpublished release.');
        }

        return $token;
    }
}
