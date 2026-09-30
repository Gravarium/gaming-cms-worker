<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\User;
use App\Security\CmsPermission;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ContentEntryDuplicationSecurityTest extends WebTestCase
{
    public function testDuplicateRouteRequiresContentPermission(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, []);
        $source = $this->createSourceEntry($client, $user);
        $sourceId = $this->requireId($source);
        $entryCount = $this->entryCount($client);
        $client->loginUser($user);

        $client->request('POST', '/admin/content/'.$sourceId.'/duplicate', [
            '_token' => 'irrelevant-csrf-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($entryCount, $this->entryCount($client));
        $storedSource = $this->findEntry($client, $sourceId);
        self::assertInstanceOf(ContentEntry::class, $storedSource);
        self::assertSame($source->getTitle(), $storedSource->getTitle());
    }

    public function testMissingAndInvalidCsrfTokensDoNotCreateDuplicates(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::CONTENT]);
        $source = $this->createSourceEntry($client, $user);
        $sourceId = $this->requireId($source);
        $entryCount = $this->entryCount($client);
        $client->loginUser($user);

        $client->request('POST', '/admin/content/'.$sourceId.'/duplicate');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/content/'.$sourceId.'/duplicate', [
            '_token' => 'invalid-csrf-token',
        ]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame($entryCount, $this->entryCount($client));
        self::assertInstanceOf(ContentEntry::class, $this->findEntry($client, $sourceId));
    }

    public function testRenderedTokenCreatesSafeDraftAndLeavesPublishedSourceUnchanged(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, [CmsPermission::CONTENT]);
        $source = $this->createSourceEntry($client, $user);
        $sourceId = $this->requireId($source);
        $entryCount = $this->entryCount($client);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/content/'.$sourceId.'/edit');
        self::assertResponseIsSuccessful();
        $token = $this->renderedDuplicateToken($crawler, $sourceId);

        $client->request('POST', '/admin/content/'.$sourceId.'/duplicate', [
            '_token' => $token,
        ]);

        self::assertTrue($client->getResponse()->isRedirect());
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/admin/content/(\d+)/edit$#', $location);
        preg_match('#^/admin/content/(\d+)/edit$#', $location, $match);
        $copyId = (int) $match[1];
        self::assertNotSame($sourceId, $copyId);

        $copy = $this->findEntry($client, $copyId);
        $storedSource = $this->findEntry($client, $sourceId);
        self::assertInstanceOf(ContentEntry::class, $copy);
        self::assertInstanceOf(ContentEntry::class, $storedSource);
        self::assertSame($entryCount + 1, $this->entryCount($client));

        self::assertSame('Kopie von '.$source->getTitle(), $copy->getTitle());
        self::assertSame($source->getType(), $copy->getType());
        self::assertSame($source->getBody(), $copy->getBody());
        self::assertSame(ContentEntry::STATUS_DRAFT, $copy->getStatus());
        self::assertNull($copy->getPublishedAt());
        self::assertFalse($copy->isFeatured());
        self::assertFalse($copy->isPinned());
        self::assertNull($copy->getCanonicalUrl());
        self::assertTrue($copy->isNoIndex());

        self::assertSame(ContentEntry::STATUS_PUBLISHED, $storedSource->getStatus());
        self::assertNotNull($storedSource->getPublishedAt());
        self::assertTrue($storedSource->isFeatured());
        self::assertTrue($storedSource->isPinned());
        self::assertSame('https://example.test/source-page', $storedSource->getCanonicalUrl());
        self::assertFalse($storedSource->isNoIndex());
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('content-duplicate-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Content duplicate security test')
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions)
            ->verifyEmail();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createSourceEntry(KernelBrowser $client, User $author): ContentEntry
    {
        $suffix = bin2hex(random_bytes(6));
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle('Published source '.$suffix)
            ->setSlug('published-source-'.$suffix)
            ->setSubtitle('Source subtitle')
            ->setExcerpt('Source excerpt')
            ->setBody('Published source body.')
            ->setStatus(ContentEntry::STATUS_PUBLISHED)
            ->setPublishedAt(new DateTimeImmutable('-1 hour'))
            ->setFeatured(true)
            ->setPinned(true)
            ->setCanonicalUrl('https://example.test/source-page')
            ->setNoIndex(false);
        $entry->synchronizePublication();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($entry);
        $entityManager->flush();

        return $entry;
    }

    private function requireId(ContentEntry $entry): int
    {
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

    private function entryCount(KernelBrowser $client): int
    {
        return $client->getContainer()->get(EntityManagerInterface::class)
            ->getRepository(ContentEntry::class)
            ->count([]);
    }

    private function renderedDuplicateToken(Crawler $crawler, int $entryId): string
    {
        $selector = sprintf(
            'form[action="/admin/content/%d/duplicate"] input[name="_token"]',
            $entryId,
        );
        $token = $crawler->filter($selector)->attr('value');
        if (!is_string($token) || $token === '') {
            throw new LogicException('The content form must render its duplicate-specific CSRF token.');
        }

        return $token;
    }
}
