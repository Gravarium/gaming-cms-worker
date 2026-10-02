<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\ForumWorkflow\ForumWorkflowGateway;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicForumEditTest extends WebTestCase
{
    public function testAuthorsCanCorrectQuestionAndReplyWithinEveryWriteBoundary(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        $this->ensureForumTables($connection);
        $forum = $container->get(ForumWorkflowGateway::class);
        $suffix = bin2hex(random_bytes(5));
        $owner = $this->user($em, 'forum-edit-owner-'.$suffix);
        $other = $this->user($em, 'forum-edit-other-'.$suffix);
        $ownerId = (int) $owner->getId();
        $otherId = (int) $other->getId();
        $roomId = $forum->saveRoom(null, 'Editable forum '.$suffix, 'public', null);

        try {
            $threadId = $forum->createThread($roomId, $ownerId, 'Original question '.$suffix, 'Original question body.');
            $replyId = $forum->reply($threadId, $ownerId, 'Original reply.', null, 0);
            $questionId = (int) $connection->fetchOne(
                'SELECT id FROM forum_post WHERE thread_id = :thread_id ORDER BY created_at, id LIMIT 1',
                ['thread_id' => $threadId],
            );

            $client->loginUser($owner);
            $crawler = $client->request('GET', '/forum/threads/'.$threadId.'/posts/'.$questionId.'/edit');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('input[name="title"]');
            $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/forum/threads/'.$threadId.'/posts/'.$questionId.'/edit', [
                '_token' => $token,
                'version' => '1',
                'title' => 'Corrected question '.$suffix,
                'body' => 'Corrected question body.',
            ]);
            self::assertResponseStatusCodeSame(303);
            self::assertSame('Corrected question '.$suffix, $connection->fetchOne('SELECT title FROM forum_thread WHERE id = :id', ['id' => $threadId]));
            self::assertSame('Corrected question body.', $connection->fetchOne('SELECT body FROM forum_post WHERE id = :id', ['id' => $questionId]));

            $crawler = $client->request('GET', '/forum/threads/'.$threadId.'/posts/'.$replyId.'/edit');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('input[name="title"]');
            $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/forum/threads/'.$threadId.'/posts/'.$replyId.'/edit', [
                '_token' => $token,
                'version' => '2',
                'body' => 'Corrected reply.',
            ]);
            self::assertResponseStatusCodeSame(303);
            self::assertSame('Corrected reply.', $connection->fetchOne('SELECT body FROM forum_post WHERE id = :id', ['id' => $replyId]));
            self::assertNotFalse($connection->fetchOne('SELECT edited_at FROM forum_post WHERE id = :id', ['id' => $replyId]));

            $client->request('GET', '/forum/threads/'.$threadId);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#post-'.$replyId, 'bearbeitet');

            $client->loginUser($other);
            $client->request('GET', '/forum/threads/'.$threadId.'/posts/'.$questionId.'/edit');
            self::assertResponseStatusCodeSame(404);

            $client->loginUser($owner);
            $before = (string) $connection->fetchOne('SELECT body FROM forum_post WHERE id = :id', ['id' => $replyId]);
            $client->request('POST', '/forum/threads/'.$threadId.'/posts/'.$replyId.'/edit', [
                '_token' => 'invalid',
                'version' => '3',
                'body' => 'CSRF tampering.',
            ]);
            self::assertResponseStatusCodeSame(403);
            self::assertSame($before, $connection->fetchOne('SELECT body FROM forum_post WHERE id = :id', ['id' => $replyId]));

            $crawler = $client->request('GET', '/forum/threads/'.$threadId.'/posts/'.$replyId.'/edit');
            $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
            $client->request('POST', '/forum/threads/'.$threadId.'/posts/'.$replyId.'/edit', [
                '_token' => $token,
                'version' => '2',
                'body' => 'Stale write.',
            ]);
            self::assertResponseRedirects('/forum/threads/'.$threadId.'/posts/'.$replyId.'/edit');
            self::assertSame($before, $connection->fetchOne('SELECT body FROM forum_post WHERE id = :id', ['id' => $replyId]));

            $connection->update('forum_thread', ['state' => 'locked'], ['id' => $threadId]);
            $client->request('GET', '/forum/threads/'.$threadId.'/posts/'.$replyId.'/edit');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $connection->delete('forum_room', ['id' => $roomId]);
            $this->removeUsers($em, [$ownerId, $otherId]);
        }
    }

    public function testHiddenRoomsAndDisabledGamingDoNotExposeEditRoutes(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        $this->ensureForumTables($connection);
        $forum = $container->get(ForumWorkflowGateway::class);
        $suffix = bin2hex(random_bytes(5));
        $owner = $this->user($em, 'forum-edit-hidden-'.$suffix);
        $ownerId = (int) $owner->getId();
        $roomId = $forum->saveRoom(null, 'Hidden forum '.$suffix, 'moderators', null);
        $threadId = $forum->createThread($roomId, $ownerId, 'Hidden question', 'Hidden body.');
        $postId = (int) $connection->fetchOne('SELECT id FROM forum_post WHERE thread_id = :id', ['id' => $threadId]);
        $createdState = false;
        $wasEnabled = true;

        try {
            $client->loginUser($owner);
            $client->request('GET', '/forum/threads/'.$threadId.'/posts/'.$postId.'/edit');
            self::assertResponseStatusCodeSame(404);

            $em = $client->getContainer()->get(EntityManagerInterface::class);
            $state = $em->find(CmsModuleState::class, 'gaming');
            $createdState = !$state instanceof CmsModuleState;
            $state = $state ?? (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
            $wasEnabled = $state->isEnabled();
            $state->setEnabled(false);
            $em->persist($state);
            $em->flush();
            $client->request('GET', '/forum/threads/'.$threadId.'/posts/'.$postId.'/edit');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $connection->delete('forum_room', ['id' => $roomId]);
            $em = $client->getContainer()->get(EntityManagerInterface::class);
            $em->clear();
            $current = $em->find(CmsModuleState::class, 'gaming');
            if ($current instanceof CmsModuleState) {
                if ($createdState) {
                    $em->remove($current);
                } else {
                    $current->setEnabled($wasEnabled);
                }
                $em->flush();
            }
            $this->removeUsers($em, [$ownerId]);
        }
    }

    private function user(EntityManagerInterface $em, string $emailPrefix): User
    {
        $user = (new User())
            ->setEmail($emailPrefix.'@example.test')
            ->setDisplayName('Forum author')
            ->verifyEmail();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** @param list<int> $userIds */
    private function removeUsers(EntityManagerInterface $em, array $userIds): void
    {
        $em->clear();
        foreach ($userIds as $userId) {
            $user = $em->find(User::class, $userId);
            if ($user instanceof User) {
                $em->remove($user);
            }
        }
        $em->flush();
    }

    private function ensureForumTables(Connection $connection): void
    {
        $schema = $connection->createSchemaManager();
        if (!$schema->tablesExist(['forum_room'])) {
            $table = new Table('forum_room');
            $table->addColumn('id', 'integer', ['autoincrement' => true]);
            $table->addColumn('guild_id', 'integer', ['notnull' => false]);
            $table->addColumn('title', 'string', ['length' => 180]);
            $table->addColumn('visibility', 'string', ['length' => 16]);
            $table->addColumn('created_at', 'datetime');
            $table->setPrimaryKey(['id']);
            $schema->createTable($table);
        }
        if (!$schema->tablesExist(['forum_thread'])) {
            $table = new Table('forum_thread');
            $table->addColumn('id', 'integer', ['autoincrement' => true]);
            $table->addColumn('room_id', 'integer');
            $table->addColumn('author_id', 'integer', ['notnull' => false]);
            $table->addColumn('solved_post_id', 'integer', ['notnull' => false]);
            $table->addColumn('title', 'string', ['length' => 180]);
            $table->addColumn('state', 'string', ['length' => 16]);
            $table->addColumn('version', 'integer');
            $table->addColumn('created_at', 'datetime');
            $table->addColumn('updated_at', 'datetime');
            $table->setPrimaryKey(['id']);
            $table->addForeignKeyConstraint('forum_room', ['room_id'], ['id'], ['onDelete' => 'CASCADE']);
            $table->addForeignKeyConstraint('cms_user', ['author_id'], ['id'], ['onDelete' => 'SET NULL']);
            $schema->createTable($table);
        }
        if (!$schema->tablesExist(['forum_post'])) {
            $table = new Table('forum_post');
            $table->addColumn('id', 'integer', ['autoincrement' => true]);
            $table->addColumn('thread_id', 'integer');
            $table->addColumn('author_id', 'integer', ['notnull' => false]);
            $table->addColumn('quoted_post_id', 'integer', ['notnull' => false]);
            $table->addColumn('body', 'text');
            $table->addColumn('created_at', 'datetime');
            $table->addColumn('edited_at', 'datetime', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addForeignKeyConstraint('forum_thread', ['thread_id'], ['id'], ['onDelete' => 'CASCADE']);
            $table->addForeignKeyConstraint('cms_user', ['author_id'], ['id'], ['onDelete' => 'SET NULL']);
            $table->addForeignKeyConstraint('forum_post', ['quoted_post_id'], ['id'], ['onDelete' => 'SET NULL']);
            $schema->createTable($table);
        }
        if (!$schema->tablesExist(['forum_subscription'])) {
            $table = new Table('forum_subscription');
            $table->addColumn('thread_id', 'integer');
            $table->addColumn('user_id', 'integer');
            $table->addColumn('created_at', 'datetime');
            $table->setPrimaryKey(['thread_id', 'user_id']);
            $table->addForeignKeyConstraint('forum_thread', ['thread_id'], ['id'], ['onDelete' => 'CASCADE']);
            $table->addForeignKeyConstraint('cms_user', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
            $schema->createTable($table);
        }
    }
}
