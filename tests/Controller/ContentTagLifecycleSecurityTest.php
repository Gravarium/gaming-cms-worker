<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ContentTagLifecycleSecurityTest extends WebTestCase
{
    public function testContentTagRoutesRequireContentPermission(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'tag-reader', [CmsPermission::ACCESS]);
        $tagId = $this->createTag($client, 'restricted');
        $client->loginUser($user);

        $client->request('GET', '/admin/content/tags');

        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/content/tags/'.$tagId.'/delete', [
            '_token' => 'invalid-csrf-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertInstanceOf(ContentTag::class, $this->findTag($client, $tagId));
    }

    public function testDeleteRejectsInvalidCsrfWithoutRemovingUnusedTag(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'csrf-manager', [CmsPermission::CONTENT]);
        $tagId = $this->createTag($client, 'csrf-target');
        $client->loginUser($user);

        $client->request('POST', '/admin/content/tags/'.$tagId.'/delete', [
            '_token' => 'invalid-csrf-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertInstanceOf(ContentTag::class, $this->findTag($client, $tagId));
    }

    public function testReferencedTagCannotBeDeletedWithItsRenderedCsrfToken(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'reference-manager', [CmsPermission::CONTENT]);
        $tagId = $this->createReferencedTag($client, $user);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/content/tags');
        self::assertResponseIsSuccessful();
        $token = $this->renderedDeleteToken($crawler, $tagId);

        $client->request('POST', '/admin/content/tags/'.$tagId.'/delete', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin/content/tags');
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.notice.text-danger', 'wird noch von Inhalten verwendet');
        self::assertInstanceOf(ContentTag::class, $this->findTag($client, $tagId));
    }

    public function testUnusedTagCanBeDeletedWithItsRenderedCsrfToken(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'delete-manager', [CmsPermission::CONTENT]);
        $tagId = $this->createTag($client, 'unused');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/content/tags');
        self::assertResponseIsSuccessful();
        $token = $this->renderedDeleteToken($crawler, $tagId);
        $client->request('POST', '/admin/content/tags/'.$tagId.'/delete', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin/content/tags');
        self::assertNull($this->findTag($client, $tagId));
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('content-tag-'.$label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Content tag '.$label)
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions)
            ->verifyEmail();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createTag(KernelBrowser $client, string $label): int
    {
        $tag = (new ContentTag())
            ->setName('Tag '.$label)
            ->setSlug('tag-'.$label.'-'.bin2hex(random_bytes(4)));
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($tag);
        $entityManager->flush();
        $id = $tag->getId();
        if ($id === null) {
            throw new LogicException('The content-tag fixture must have an identifier.');
        }

        return $id;
    }

    private function createReferencedTag(KernelBrowser $client, User $author): int
    {
        $suffix = bin2hex(random_bytes(6));
        $tag = (new ContentTag())
            ->setName('Referenced tag '.$suffix)
            ->setSlug('referenced-tag-'.$suffix);
        $entry = (new ContentEntry())
            ->setAuthor($author)
            ->setTitle('Entry for referenced tag '.$suffix)
            ->setSlug('entry-for-tag-'.$suffix)
            ->addTag($tag);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($tag);
        $entityManager->persist($entry);
        $entityManager->flush();
        $id = $tag->getId();
        if ($id === null) {
            throw new LogicException('The referenced content-tag fixture must have an identifier.');
        }

        return $id;
    }

    private function findTag(KernelBrowser $client, int $id): ?ContentTag
    {
        $tag = $client->getContainer()->get(EntityManagerInterface::class)->find(ContentTag::class, $id);

        return $tag instanceof ContentTag ? $tag : null;
    }

    private function renderedDeleteToken(Crawler $crawler, int $tagId): string
    {
        $selector = sprintf(
            'form[action="/admin/content/tags/%d/delete"] input[name="_token"]',
            $tagId,
        );
        $token = $crawler->filter($selector)->attr('value');
        if (!is_string($token) || $token === '') {
            throw new LogicException('The tag list must render a CSRF token for every delete action.');
        }

        return $token;
    }
}
