<?php

declare(strict_types=1);

namespace App\Tests\ForumWorkflow;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\ForumWorkflow\ForumWorkflowGateway;
use App\Security\CmsPermission;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ForumWorkflowControllerTest extends WebTestCase
{
    public function testPublicVisibilityCsrfAndThreadCreation(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        $this->ensureForumTables($connection);
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

            $token = $this->csrfToken($client, '/forum/rooms/'.$publicRoom);
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

        } finally {
            foreach ($roomIds as $roomId) {
                $connection->delete('forum_room', ['id' => $roomId]);
            }
            $this->removeUsers($entityManager, $userIds);
        }
    }

    public function testGamingAdministratorCanCreateAndEditRooms(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        $this->ensureForumTables($connection);
        $suffix = bin2hex(random_bytes(5));
        $roomIds = [];
        $userIds = [];

        try {
            $admin = $this->user($entityManager, 'forum-admin-'.$suffix, [CmsPermission::GAMING]);
            $userIds[] = $admin->getId();
            $client->loginUser($admin);
            $client->request('GET', '/admin/gaming/forum/rooms');
            self::assertResponseIsSuccessful();

            $token = $this->csrfToken($client, '/admin/gaming/forum/rooms/new');
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

            $token = $this->csrfToken($client, '/admin/gaming/forum/rooms/'.$roomId.'/edit');
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

    private function csrfToken(KernelBrowser $client, string $path): string
    {
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('form input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        return $token;
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

    /**
     * The CI test schema is generated from ORM mappings; Forum tables are migration-only.
     */
    private function ensureForumTables(Connection $connection): void
    {
        $schemaManager = $connection->createSchemaManager();

        if (!$schemaManager->tablesExist(['forum_room'])) {
            $room = new Table('forum_room');
            $room->addColumn('id', 'integer', ['autoincrement' => true]);
            $room->addColumn('guild_id', 'integer', ['notnull' => false]);
            $room->addColumn('title', 'string', ['length' => 180]);
            $room->addColumn('visibility', 'string', ['length' => 16]);
            $room->addColumn('created_at', 'datetime');
            $room->setPrimaryKey(['id']);
            $room->addIndex(['guild_id', 'visibility'], 'IDX_FORUM_ROOM_GUILD');
            $room->addForeignKeyConstraint('guild', ['guild_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_FORUM_ROOM_GUILD');
            $schemaManager->createTable($room);
        }

        if (!$schemaManager->tablesExist(['forum_thread'])) {
            $thread = new Table('forum_thread');
            $thread->addColumn('id', 'integer', ['autoincrement' => true]);
            $thread->addColumn('room_id', 'integer');
            $thread->addColumn('author_id', 'integer', ['notnull' => false]);
            $thread->addColumn('solved_post_id', 'integer', ['notnull' => false]);
            $thread->addColumn('title', 'string', ['length' => 180]);
            $thread->addColumn('state', 'string', ['length' => 16]);
            $thread->addColumn('version', 'integer');
            $thread->addColumn('created_at', 'datetime');
            $thread->addColumn('updated_at', 'datetime');
            $thread->setPrimaryKey(['id']);
            $thread->addIndex(['room_id', 'state', 'updated_at'], 'IDX_FORUM_THREAD_ROOM');
            $thread->addForeignKeyConstraint('forum_room', ['room_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_FORUM_THREAD_ROOM');
            $thread->addForeignKeyConstraint('cms_user', ['author_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_FORUM_THREAD_AUTHOR');
            $schemaManager->createTable($thread);
        }

        if (!$schemaManager->tablesExist(['forum_post'])) {
            $post = new Table('forum_post');
            $post->addColumn('id', 'integer', ['autoincrement' => true]);
            $post->addColumn('thread_id', 'integer');
            $post->addColumn('author_id', 'integer', ['notnull' => false]);
            $post->addColumn('quoted_post_id', 'integer', ['notnull' => false]);
            $post->addColumn('body', 'text');
            $post->addColumn('created_at', 'datetime');
            $post->addColumn('edited_at', 'datetime', ['notnull' => false]);
            $post->setPrimaryKey(['id']);
            $post->addIndex(['thread_id', 'created_at'], 'IDX_FORUM_POST_THREAD');
            $post->addForeignKeyConstraint('forum_thread', ['thread_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_FORUM_POST_THREAD');
            $post->addForeignKeyConstraint('cms_user', ['author_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_FORUM_POST_AUTHOR');
            $post->addForeignKeyConstraint('forum_post', ['quoted_post_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_FORUM_POST_QUOTE');
            $schemaManager->createTable($post);
        }

        if (!$schemaManager->tablesExist(['forum_subscription'])) {
            $subscription = new Table('forum_subscription');
            $subscription->addColumn('thread_id', 'integer');
            $subscription->addColumn('user_id', 'integer');
            $subscription->addColumn('created_at', 'datetime');
            $subscription->setPrimaryKey(['thread_id', 'user_id']);
            $subscription->addForeignKeyConstraint('forum_thread', ['thread_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_FORUM_SUB_THREAD');
            $subscription->addForeignKeyConstraint('cms_user', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_FORUM_SUB_USER');
            $schemaManager->createTable($subscription);
        }

        if (!$schemaManager->tablesExist(['forum_moderation_audit'])) {
            $audit = new Table('forum_moderation_audit');
            $audit->addColumn('id', 'integer', ['autoincrement' => true]);
            $audit->addColumn('thread_id', 'integer');
            $audit->addColumn('actor_id', 'integer', ['notnull' => false]);
            $audit->addColumn('state', 'string', ['length' => 16]);
            $audit->addColumn('reason', 'string', ['length' => 500]);
            $audit->addColumn('occurred_at', 'datetime');
            $audit->setPrimaryKey(['id']);
            $audit->addIndex(['thread_id', 'occurred_at'], 'IDX_FORUM_MOD_AUDIT');
            $audit->addForeignKeyConstraint('forum_thread', ['thread_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_FORUM_MOD_THREAD');
            $audit->addForeignKeyConstraint('cms_user', ['actor_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_FORUM_MOD_ACTOR');
            $schemaManager->createTable($audit);
        }
    }
}
