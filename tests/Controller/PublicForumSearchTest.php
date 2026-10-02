<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\ForumWorkflow\ForumWorkflowGateway;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicForumSearchTest extends WebTestCase
{
    public function testSearchHasExactCountAndBoundedStablePages(): void
    {
        $client = static::createClient();
        $connection = $client->getContainer()->get(Connection::class);
        $this->ensureTables($connection);
        $room = $client->getContainer()->get(ForumWorkflowGateway::class)
            ->saveRoom(null, 'Paged forum '.bin2hex(random_bytes(5)), 'public', null);
        try {
            for ($index = 0; $index < 21; ++$index) {
                $this->thread($connection, $room, 'Paged-search topic '.$index, 'Some ordinary text');
            }
            $client->request('GET', '/forum/rooms/'.$room.'/search?'.http_build_query(['q' => 'Paged-search']));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', '21 passende Themen');
            self::assertCount(20, $client->getCrawler()->filter('article.panel'));

            $client->request('GET', '/forum/rooms/'.$room.'/search?'.http_build_query(['q' => 'Paged-search', 'page' => 2]));
            self::assertResponseIsSuccessful();
            self::assertCount(1, $client->getCrawler()->filter('article.panel'));
            self::assertSelectorTextContains('body', 'Seite 2 von 2');
        } finally {
            $connection->delete('forum_room', ['id' => $room]);
        }
    }

    public function testRoomScopedSearchMatchesTitlesAndRepliesWithoutExposingBodiesOrOtherRooms(): void
    {
        $client = static::createClient();
        $connection = $client->getContainer()->get(Connection::class);
        $this->ensureTables($connection);
        $forum = $client->getContainer()->get(ForumWorkflowGateway::class);
        $suffix = bin2hex(random_bytes(6));
        $public = $forum->saveRoom(null, 'Search public '.$suffix, 'public', null);
        $members = $forum->saveRoom(null, 'Search members '.$suffix, 'members', null);

        try {
            $title = $this->thread($connection, $public, 'The literal 100% guide '.$suffix, 'Ordinary body');
            $body = $this->thread($connection, $public, 'Another helpful topic '.$suffix, 'Private reply sentinel 100% '.$suffix);
            $this->thread($connection, $public, 'A wildcard only topic '.$suffix, '1000 reasons');
            $hidden = $this->thread($connection, $members, 'Members 100% '.$suffix, 'Hidden room reply '.$suffix);

            $client->request('GET', '/forum/rooms/'.$public.'/search?'.http_build_query(['q' => '100%']));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', '2 passende Themen');
            self::assertSelectorTextContains('body', 'The literal 100% guide '.$suffix);
            self::assertSelectorTextContains('body', 'Another helpful topic '.$suffix);
            self::assertSelectorTextNotContains('body', 'A wildcard only topic '.$suffix);
            self::assertSelectorTextNotContains('body', 'Members 100% '.$suffix);
            self::assertSelectorTextNotContains('body', 'Private reply sentinel');
            self::assertStringNotContainsString('Hidden room reply', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
            self::assertStringContainsString('noindex', $client->getResponse()->headers->get('X-Robots-Tag', ''));
            self::assertNotSame($title, $body);
            self::assertNotSame($title, $hidden);

            $client->request('GET', '/forum/rooms/'.$members.'/search?'.http_build_query(['q' => '100%']));
            self::assertResponseStatusCodeSame(404);
            foreach (['x', '%'] as $term) {
                $client->request('GET', '/forum/rooms/'.$public.'/search?'.http_build_query(['q' => $term]));
                self::assertResponseStatusCodeSame(404);
            }
            $client->request('GET', '/forum/rooms/'.$public.'/search?'.http_build_query(['q' => '100%', 'page' => 1001]));
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/forum/rooms/'.$public.'/search?'.http_build_query(['q' => '100%', 'page' => 2]));
            self::assertResponseStatusCodeSame(404);

            $em = $client->getContainer()->get(EntityManagerInterface::class);
            $member = (new User())->setEmail('forum-search-'.$suffix.'@example.test')
                ->setDisplayName('Forum search member')->verifyEmail();
            $em->persist($member);
            $em->flush();
            try {
                $client->restart();
                $client->loginUser($member);
                $client->request('GET', '/forum/rooms/'.$members.'/search?'.http_build_query(['q' => '100%']));
                self::assertResponseIsSuccessful();
                self::assertSelectorTextContains('body', 'Members 100% '.$suffix);
                self::assertSelectorTextNotContains('body', 'Hidden room reply '.$suffix);
            } finally {
                $managed = $client->getContainer()->get(EntityManagerInterface::class)->find(User::class, $member->getId());
                if ($managed instanceof User) {
                    $client->getContainer()->get(EntityManagerInterface::class)->remove($managed);
                    $client->getContainer()->get(EntityManagerInterface::class)->flush();
                }
            }
        } finally {
            $connection->delete('forum_room', ['id' => $public]);
            $connection->delete('forum_room', ['id' => $members]);
        }
    }

    private function thread(Connection $connection, int $room, string $title, string $body): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $connection->insert('forum_thread', [
            'room_id' => $room, 'author_id' => null, 'solved_post_id' => null,
            'title' => $title, 'state' => 'open', 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $connection->lastInsertId();
        $connection->insert('forum_post', [
            'thread_id' => $id, 'author_id' => null, 'quoted_post_id' => null,
            'body' => $body, 'created_at' => $now, 'edited_at' => null,
        ]);
        return $id;
    }

    private function ensureTables(Connection $connection): void
    {
        $manager = $connection->createSchemaManager();
        if (!$manager->tablesExist(['forum_room'])) {
            $room = new Table('forum_room');
            $room->addColumn('id', 'integer', ['autoincrement' => true]);
            $room->addColumn('guild_id', 'integer', ['notnull' => false]);
            $room->addColumn('title', 'string', ['length' => 180]);
            $room->addColumn('visibility', 'string', ['length' => 16]);
            $room->addColumn('created_at', 'datetime');
            $room->setPrimaryKey(['id']);
            $manager->createTable($room);
        }
        if (!$manager->tablesExist(['forum_thread'])) {
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
            $thread->addForeignKeyConstraint('forum_room', ['room_id'], ['id'], ['onDelete' => 'CASCADE']);
            $manager->createTable($thread);
        }
        if (!$manager->tablesExist(['forum_post'])) {
            $post = new Table('forum_post');
            $post->addColumn('id', 'integer', ['autoincrement' => true]);
            $post->addColumn('thread_id', 'integer');
            $post->addColumn('author_id', 'integer', ['notnull' => false]);
            $post->addColumn('quoted_post_id', 'integer', ['notnull' => false]);
            $post->addColumn('body', 'text');
            $post->addColumn('created_at', 'datetime');
            $post->addColumn('edited_at', 'datetime', ['notnull' => false]);
            $post->setPrimaryKey(['id']);
            $post->addForeignKeyConstraint('forum_thread', ['thread_id'], ['id'], ['onDelete' => 'CASCADE']);
            $manager->createTable($post);
        }
    }
}
