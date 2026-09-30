<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\ForumWorkflow\ForumWorkflowGateway;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicForumSubscriptionTest extends WebTestCase
{
    public function testOnlyOwnCurrentlyVisibleSubscriptionsAreListed(): void
    {
        $client = static::createClient();
        $connection = $client->getContainer()->get(Connection::class);
        $this->ensureTables($connection);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $forum = $client->getContainer()->get(ForumWorkflowGateway::class);
        $suffix = bin2hex(random_bytes(5));
        $owner = (new User())->setEmail('forum-sub-'.$suffix.'@example.test')->setDisplayName('Subscriber')->verifyEmail();
        $other = (new User())->setEmail('forum-other-'.$suffix.'@example.test')->setDisplayName('Other subscriber')->verifyEmail();
        $em->persist($owner);
        $em->persist($other);
        $em->flush();
        $public = $forum->saveRoom(null, 'Public subscriptions '.$suffix, 'public', null);
        $private = $forum->saveRoom(null, 'Private subscriptions '.$suffix, 'members', null);

        try {
            $visible = $forum->createThread($public, (int) $owner->getId(), 'Own public topic '.$suffix, 'Sensitive body '.$suffix);
            $hidden = $forum->createThread($private, (int) $owner->getId(), 'Hidden member topic '.$suffix, 'Private body');
            $foreign = $forum->createThread($public, (int) $other->getId(), 'Other subscription '.$suffix, 'Another body');
            $forum->setSubscription($visible, (int) $owner->getId(), true);
            $forum->setSubscription($hidden, (int) $owner->getId(), true);
            $forum->setSubscription($foreign, (int) $other->getId(), true);

            $client->request('GET', '/forum/subscriptions');
            self::assertResponseStatusCodeSame(302);
            $client->loginUser($owner);
            $client->request('GET', '/forum');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Meine abonnierten Themen');
            $client->request('GET', '/forum/subscriptions');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', '2 sichtbare Themen');
            self::assertSelectorTextContains('body', 'Own public topic '.$suffix);
            self::assertSelectorTextContains('body', 'Hidden member topic '.$suffix);
            self::assertSelectorTextNotContains('body', 'Other subscription '.$suffix);
            self::assertSelectorTextNotContains('body', 'Sensitive body '.$suffix);
            self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
            self::assertStringContainsString('noindex', $client->getResponse()->headers->get('X-Robots-Tag', ''));

            $connection->update('forum_room', ['visibility' => 'moderators'], ['id' => $private]);
            $client->request('GET', '/forum/subscriptions');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', '1 sichtbare Themen');
            self::assertSelectorTextNotContains('body', 'Hidden member topic '.$suffix);
            $client->request('GET', '/forum/subscriptions?page=2');
            self::assertResponseStatusCodeSame(404);
            foreach (['0', '1001', '-1', '1%20', 'x'] as $invalid) {
                $client->request('GET', '/forum/subscriptions?page='.$invalid);
                self::assertResponseStatusCodeSame(404);
            }
        } finally {
            $connection->delete('forum_room', ['id' => $public]);
            $connection->delete('forum_room', ['id' => $private]);
            $em->clear();
            foreach ([$owner->getId(), $other->getId()] as $id) {
                $user = $em->find(User::class, $id);
                if ($user instanceof User) {
                    $em->remove($user);
                }
            }
            $em->flush();
        }
    }

    public function testStablePaginationWithExactVisibleTotal(): void
    {
        $client = static::createClient();
        $connection = $client->getContainer()->get(Connection::class);
        $this->ensureTables($connection);
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $forum = $client->getContainer()->get(ForumWorkflowGateway::class);
        $suffix = bin2hex(random_bytes(5));
        $user = (new User())->setEmail('forum-page-'.$suffix.'@example.test')->setDisplayName('Paged subscriber')->verifyEmail();
        $em->persist($user);
        $em->flush();
        $room = $forum->saveRoom(null, 'Subscription pages '.$suffix, 'public', null);

        try {
            for ($index = 0; $index < 21; ++$index) {
                $thread = $forum->createThread($room, (int) $user->getId(), 'Subscribed '.$index.' '.$suffix, 'Body');
                $forum->setSubscription($thread, (int) $user->getId(), true);
            }
            $client->loginUser($user);
            $client->request('GET', '/forum/subscriptions');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', '21 sichtbare Themen');
            self::assertCount(20, $client->getCrawler()->filter('article.panel'));
            $client->request('GET', '/forum/subscriptions?page=2');
            self::assertResponseIsSuccessful();
            self::assertCount(1, $client->getCrawler()->filter('article.panel'));
            self::assertSelectorTextContains('body', 'Seite 2 von 2');
        } finally {
            $connection->delete('forum_room', ['id' => $room]);
            $em->clear();
            $managed = $em->find(User::class, $user->getId());
            if ($managed instanceof User) {
                $em->remove($managed);
                $em->flush();
            }
        }
    }

    private function ensureTables(Connection $connection): void
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
            $table->addForeignKeyConstraint('guild', ['guild_id'], ['id'], ['onDelete' => 'CASCADE']);
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
