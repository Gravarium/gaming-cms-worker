<?php

declare(strict_types=1);

namespace App\Tests\ForumWorkflow;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\ForumWorkflow\ForumWorkflowGateway;
use App\Security\CmsPermission;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ForumWorkflowControllerTest extends WebTestCase
{
    public function testPublicVisibilityCsrfThreadCreationAndRoomAdministration(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        $gateway = $container->get(ForumWorkflowGateway::class);
        $suffix = bin2hex(random_bytes(5));
        $publicRoom = $gateway->saveRoom(null, 'Visible forum '.$suffix, 'public', null);
        $membersRoom = $gateway->saveRoom(null, 'Member forum '.$suffix, 'members', null);
        $roomIds = [$publicRoom, $membersRoom];
        $userIds = [];

        try {
            $client->request('GET', '/forum');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Visible forum '.$suffix);
            self::assertSelectorTextNotContains('body', 'Member forum '.$suffix);

            $poster = $this->user($entityManager, 'forum-poster-'.$suffix, []);
            $userIds[] = $poster->getId();
            $client->loginUser($poster);
            $client->request('GET', '/forum');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Member forum '.$suffix);

            $client->request('POST', '/forum/rooms/'.$publicRoom.'/threads', ['title' => 'Blocked without token', 'body' => 'This must not persist.']);
            self::assertResponseStatusCodeSame(403);
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM forum_thread WHERE room_id = :id', ['id' => $publicRoom]));

            $token = $this->csrfToken($client, 'forum_thread_'.$publicRoom);
            $client->request('POST', '/forum/rooms/'.$publicRoom.'/threads', [
                '_token' => $token,
                'title' => 'A usable question '.$suffix,
                'body' => 'First forum post.',
            ]);
            self::assertResponseRedirects();
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'A usable question '.$suffix);
            self::assertSelectorTextContains('body', 'First forum post.');

            $admin = $this->user($entityManager, 'forum-admin-'.$suffix, [CmsPermission::GAMING]);
            $userIds[] = $admin->getId();
            $client->loginUser($admin);
            $client->request('GET', '/admin/gaming/forum/rooms');
            self::assertResponseIsSuccessful();

            $token = $this->csrfToken($client, 'forum_room_new');
            $client->request('POST', '/admin/gaming/forum/rooms/new', [
                '_token' => $token,
                'title' => 'Admin-created room '.$suffix,
                'visibility' => 'public',
                'guild_id' => '',
            ]);
            self::assertResponseRedirects();
            $adminRoom = $connection->fetchAssociative('SELECT id, title FROM forum_room WHERE title = :title', ['title' => 'Admin-created room '.$suffix]);
            self::assertIsArray($adminRoom);
            $roomId = (int) $adminRoom['id'];
            $roomIds[] = $roomId;

            $token = $this->csrfToken($client, 'forum_room_edit_'.$roomId);
            $client->request('POST', '/admin/gaming/forum/rooms/'.$roomId.'/edit', [
                '_token' => $token,
                'title' => 'Edited admin room '.$suffix,
                'visibility' => 'members',
                'guild_id' => '',
            ]);
            self::assertResponseRedirects();
            self::assertSame('Edited admin room '.$suffix, $connection->fetchOne('SELECT title FROM forum_room WHERE id = :id', ['id' => $roomId]));
            self::assertSame('members', $connection->fetchOne('SELECT visibility FROM forum_room WHERE id = :id', ['id' => $roomId]));
        } finally {
            foreach ($roomIds as $roomId) {
                $connection->delete('forum_room', ['id' => $roomId]);
            }
            $this->removeUsers($entityManager, $userIds);
        }
    }

    public function testGamingDisableHidesForumAndAdminRoutesRequirePermission(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $existing = $entityManager->find(CmsModuleState::class, 'gaming');
        $created = !$existing instanceof CmsModuleState;
        $originalEnabled = $existing?->isEnabled() ?? true;
        $state = $existing ?? (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
        $state->setEnabled(false);
        $entityManager->persist($state);
        $entityManager->flush();

        try {
            $client->request('GET', '/forum');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $entityManager->clear();
            $current = $entityManager->find(CmsModuleState::class, 'gaming');
            if ($current instanceof CmsModuleState) {
                if ($created) {
                    $entityManager->remove($current);
                } else {
                    $current->setEnabled($originalEnabled);
                }
                $entityManager->flush();
            }
        }

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $member = $this->user($entityManager, 'forum-limited-'.bin2hex(random_bytes(4)), []);
        $client->loginUser($member);
        $client->request('GET', '/admin/gaming/forum/rooms');
        self::assertResponseStatusCodeSame(403);
        $this->removeUsers($entityManager, [$member->getId()]);
    }

    /**
     * @param list<string> $permissions
     */
    private function user(EntityManagerInterface $entityManager, string $emailPrefix, array $permissions): User
    {
        $user = (new User())
            ->setEmail($emailPrefix.'@example.test')
            ->setDisplayName('Forum test user')
            ->setPermissions($permissions)
            ->verifyEmail();
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function csrfToken(KernelBrowser $client, string $id): string
    {
        return $client->getContainer()->get(CsrfTokenManagerInterface::class)->getToken($id)->getValue();
    }

    /** @param list<int|null> $userIds */
    private function removeUsers(EntityManagerInterface $entityManager, array $userIds): void
    {
        $entityManager->clear();
        foreach ($userIds as $userId) {
            if ($userId === null) {
                continue;
            }
            $user = $entityManager->find(User::class, $userId);
            if ($user instanceof User) {
                $entityManager->remove($user);
            }
        }
        $entityManager->flush();
    }
}
