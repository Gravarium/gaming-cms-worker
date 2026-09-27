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

final class AdminContentPreviewSecurityTest extends WebTestCase
{
    public function testContentManagerCanPreviewDraftPrivatelyWhilePublicPageRemainsUnavailable(): void
    {
        $client = static::createClient();
        $fixture = $this->createFixture($client, true);

        try {
            $client->loginUser($fixture['user']);
            $client->request('GET', '/admin/content/'.$fixture['entryId'].'/preview');

            self::assertResponseIsSuccessful();
            $cacheControl = array_map(
                'trim',
                explode(',', strtolower((string) $client->getResponse()->headers->get('Cache-Control'))),
            );
            self::assertContains('private', $cacheControl);
            self::assertContains('no-store', $cacheControl);
            self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow, noarchive');
            self::assertSelectorTextContains('body', $fixture['marker']);

            $client->request('GET', '/page/'.$fixture['slug']);

            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString($fixture['marker'], (string) $client->getResponse()->getContent());
        } finally {
            $this->cleanup($client, $fixture['entryId'], $fixture['userId']);
        }
    }

    public function testUserWithoutContentManagementCannotReadDraftPreview(): void
    {
        $client = static::createClient();
        $fixture = $this->createFixture($client, false);

        try {
            $client->loginUser($fixture['user']);
            $client->request('GET', '/admin/content/'.$fixture['entryId'].'/preview');

            self::assertResponseStatusCodeSame(403);
            self::assertStringNotContainsString($fixture['marker'], (string) $client->getResponse()->getContent());
        } finally {
            $this->cleanup($client, $fixture['entryId'], $fixture['userId']);
        }
    }

    /**
     * @return array{entryId: int, userId: int, slug: string, marker: string, user: User}
     */
    private function createFixture(KernelBrowser $client, bool $canManageContent): array
    {
        $suffix = bin2hex(random_bytes(6));
        $marker = 'private-draft-preview-'.$suffix;
        $user = (new User())
            ->setEmail('content-preview-'.$suffix.'@example.test')
            ->setDisplayName('Content preview test '.$suffix)
            ->setPermissions($canManageContent ? [CmsPermission::ACCESS, CmsPermission::CONTENT] : [CmsPermission::ACCESS])
            ->verifyEmail();
        $entry = (new ContentEntry())
            ->setAuthor($user)
            ->setType(ContentEntry::TYPE_PAGE)
            ->setTitle('Draft preview '.$suffix)
            ->setSlug($marker)
            ->setBody($marker)
            ->setSeoDescription('Private draft preview '.$suffix);

        $entityManager = $this->entityManager($client);
        $entityManager->persist($user);
        $entityManager->persist($entry);
        $entityManager->flush();

        return [
            'entryId' => $this->requireId($entry->getId()),
            'userId' => $this->requireId($user->getId()),
            'slug' => $entry->getSlug(),
            'marker' => $marker,
            'user' => $user,
        ];
    }

    private function cleanup(KernelBrowser $client, int $entryId, int $userId): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        $entry = $entityManager->find(ContentEntry::class, $entryId);
        if ($entry instanceof ContentEntry) {
            $entityManager->remove($entry);
        }

        $user = $entityManager->find(User::class, $userId);
        if ($user instanceof User) {
            $entityManager->remove($user);
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
