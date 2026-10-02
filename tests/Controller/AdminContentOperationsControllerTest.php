<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminContentOperationsControllerTest extends WebTestCase
{
    public function testAnonymousAndInsufficientlyPrivilegedUsersCannotReadQueue(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/content/operations');
        self::assertResponseRedirects('/login');

        $user = $this->user($client, []);
        try {
            $client->loginUser($user);
            $client->request('GET', '/admin/content/operations');
            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->cleanup($client, [], $user->getId());
        }
    }

    public function testManagerGetsPrivateReadOnlyQueueAndHistoryNavigation(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::CONTENT]);
        $managerId = $manager->getId();
        $marker = 'editorial-operations-'.bin2hex(random_bytes(6));
        $entry = (new ContentEntry())
            ->setAuthor($manager)
            ->setType(ContentEntry::TYPE_NEWS)
            ->setTitle($marker)
            ->setSlug('editorial-operations-'.bin2hex(random_bytes(6)))
            ->setBody('Read-only queue fixture.')
            ->setStatus(ContentEntry::STATUS_REVIEW);
        $this->em($client)->persist($entry);
        $this->em($client)->flush();
        $entryId = $entry->getId();
        if ($entryId === null) {
            throw new \LogicException('The editorial queue fixture was not persisted.');
        }
        $updatedAt = $entry->getUpdatedAt()->format(DATE_ATOM);

        try {
            $client->loginUser($manager);
            $client->request('GET', '/admin/content/operations', ['q' => $marker]);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString($marker, (string) $client->getResponse()->getContent());
            self::assertStringContainsString('/admin/content/'.$entryId.'/edit', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow, noarchive', $client->getResponse()->headers->get('X-Robots-Tag'));

            $historyCrawler = $client->request('GET', '/admin/content/'.$entryId.'/history');
            self::assertResponseIsSuccessful();
            self::assertGreaterThan(0, $historyCrawler->filter('a[href="/admin/content/operations"]')->count());
            self::assertStringContainsString('Noch keine Versionen vorhanden.', (string) $client->getResponse()->getContent());

            $em = $this->em($client);
            $em->clear();
            $stored = $em->find(ContentEntry::class, $entryId);
            self::assertInstanceOf(ContentEntry::class, $stored);
            self::assertSame(ContentEntry::STATUS_REVIEW, $stored->getStatus());
            self::assertSame($updatedAt, $stored->getUpdatedAt()->format(DATE_ATOM));

            $client->request('POST', '/admin/content/operations');
            self::assertResponseStatusCodeSame(405);
        } finally {
            $this->cleanup($client, [$entryId], $managerId);
        }
    }

    public function testDisabledContentModuleFailsClosed(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::CONTENT]);
        $managerId = $manager->getId();
        $previousEnabled = $this->setContentEnabled($client, false);

        try {
            $client->loginUser($manager);
            $client->request('GET', '/admin/content/operations');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreContentEnabled($client, $previousEnabled);
            $this->cleanup($client, [], $managerId);
        }
    }

    public function testMalformedFiltersAndPagesReturnBadRequest(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, [CmsPermission::CONTENT]);
        $managerId = $manager->getId();

        try {
            $client->loginUser($manager);
            foreach ([
                '/admin/content/operations?page[]=1',
                '/admin/content/operations?page=01',
                '/admin/content/operations?kind=unknown',
                '/admin/content/operations?type[]=news',
                '/admin/content/operations?q='.str_repeat('x', 121),
            ] as $url) {
                $client->request('GET', $url);
                self::assertResponseStatusCodeSame(400);
            }
        } finally {
            $this->cleanup($client, [], $managerId);
        }
    }

    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('editorial-operations-manager-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Editorial operations manager')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function setContentEnabled(KernelBrowser $client, bool $enabled): ?bool
    {
        $em = $this->em($client);
        $state = $em->getRepository(CmsModuleState::class)->find('content');
        $previous = $state?->isEnabled();
        if ($state === null) {
            $state = (new CmsModuleState())->setModuleKey('content');
        }
        $state->setEnabled($enabled);
        $em->persist($state);
        $em->flush();

        return $previous;
    }

    private function restoreContentEnabled(KernelBrowser $client, ?bool $previous): void
    {
        $em = $this->em($client);
        $em->clear();
        $state = $em->find(CmsModuleState::class, 'content');
        if ($state === null) {
            return;
        }
        if ($previous === null) {
            $em->remove($state);
        } else {
            $state->setEnabled($previous);
        }
        $em->flush();
        $em->clear();
    }

    /**
     * @param list<int> $entryIds
     */
    private function cleanup(KernelBrowser $client, array $entryIds, ?int $userId): void
    {
        $em = $this->em($client);
        $em->clear();
        foreach ($entryIds as $entryId) {
            $entry = $em->find(ContentEntry::class, $entryId);
            if ($entry instanceof ContentEntry) {
                $em->remove($entry);
            }
        }
        if ($userId !== null) {
            $user = $em->find(User::class, $userId);
            if ($user instanceof User) {
                $em->remove($user);
            }
        }
        $em->flush();
        $em->clear();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
