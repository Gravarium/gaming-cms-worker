<?php

declare(strict_types=1);

namespace App\Tests\ForumWorkflow;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\User;
use App\ForumWorkflow\ForumWorkflowGateway;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ForumWorkflowGatewayTest extends WebTestCase
{
    public function testRoomVisibilityThreadLifecycleAndAuditedModeration(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        $gateway = $container->get(ForumWorkflowGateway::class);
        $suffix = bin2hex(random_bytes(5));
        $user = (new User())
            ->setEmail('forum-workflow-'.$suffix.'@example.test')
            ->setDisplayName('Forum workflow '.$suffix)
            ->verifyEmail();
        $entityManager->persist($user);
        $entityManager->flush();
        $userId = $user->getId();
        self::assertNotNull($userId);

        $game = (new Game())->setName('Forum game '.$suffix)->setSlug('forum-game-'.$suffix);
        $entityManager->persist($game);
        $entityManager->flush();
        $gameId = $game->getId();
        self::assertNotNull($gameId);

        $guildOne = (new Guild())
            ->setGame($game)
            ->setName('Forum guild one '.$suffix)
            ->setSlug('forum-guild-one-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Forum workflow test');
        $guildTwo = (new Guild())
            ->setGame($game)
            ->setName('Forum guild two '.$suffix)
            ->setSlug('forum-guild-two-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Forum workflow test');
        $entityManager->persist($guildOne);
        $entityManager->persist($guildTwo);
        $entityManager->flush();
        $guildOneId = $guildOne->getId();
        $guildTwoId = $guildTwo->getId();
        self::assertNotNull($guildOneId);
        self::assertNotNull($guildTwoId);

        $roomIds = [];
        try {
            $connection->insert('guild_member', [
                'guild_id' => $guildOneId,
                'user_id' => $userId,
                'character_name' => 'Member one',
                'rank_name' => 'Member',
                'active' => true,
                'position' => 0,
                'leader' => false,
            ]);
            $publicRoom = $gateway->saveRoom(null, 'Forum public '.$suffix, 'public', null);
            $memberRoom = $gateway->saveRoom(null, 'Forum members '.$suffix, 'members', null);
            $guildRoom = $gateway->saveRoom(null, 'Forum guild one '.$suffix, 'guild', $guildOneId);
            $otherGuildRoom = $gateway->saveRoom(null, 'Forum guild two '.$suffix, 'guild', $guildTwoId);
            $roomIds = [$publicRoom, $memberRoom, $guildRoom, $otherGuildRoom];

            $anonymousRooms = $gateway->visibleRooms(null, [], false, true, 100);
            self::assertSame([$publicRoom], array_map(static fn (array $room): int => (int) $room['id'], $anonymousRooms));

            $guildIds = $gateway->guildIdsForUser($userId);
            self::assertSame([$guildOneId], $guildIds);
            $memberRooms = $gateway->visibleRooms($userId, $guildIds, false, true, 100);
            $visibleIds = array_map(static fn (array $room): int => (int) $room['id'], $memberRooms);
            self::assertContains($publicRoom, $visibleIds);
            self::assertContains($memberRoom, $visibleIds);
            self::assertContains($guildRoom, $visibleIds);
            self::assertNotContains($otherGuildRoom, $visibleIds);
            self::assertSame(0, $gateway->visibleRoomCount($userId, $guildIds, false, false));

            $threadId = $gateway->createThread($publicRoom, $userId, 'Question '.$suffix, 'Initial question');
            $initialPostId = (int) $connection->fetchOne(
                'SELECT id FROM forum_post WHERE thread_id = :thread_id',
                ['thread_id' => $threadId],
            );
            $replyId = $gateway->reply($threadId, $userId, 'Answer', $initialPostId, 0);
            self::assertGreaterThan(0, $replyId);
            self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM forum_post WHERE thread_id = :id', ['id' => $threadId]));
            self::assertSame(1, (int) $connection->fetchOne('SELECT version FROM forum_thread WHERE id = :id', ['id' => $threadId]));
            $this->expectStaleReply($gateway, $threadId, $userId);

            self::assertFalse($gateway->markSolved($threadId, $replyId, $userId + 1, 1));
            self::assertTrue($gateway->markSolved($threadId, $replyId, $userId, 1));
            self::assertTrue($gateway->isSubscribed($threadId, $userId) === false);
            $gateway->setSubscription($threadId, $userId, true);
            self::assertTrue($gateway->isSubscribed($threadId, $userId));
            self::assertTrue($gateway->moderateThread($threadId, $userId, 'locked', 'Resolved for review', 2));
            self::assertFalse($gateway->moderateThread($threadId, $userId, 'open', 'Stale update', 2));
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM forum_moderation_audit WHERE thread_id = :id', ['id' => $threadId]));
            self::assertSame(1, count($gateway->moderationHistory($threadId)));

            self::assertTrue($gateway->moderateThread($threadId, $userId, 'open', 'Reopened for follow-up', 3));
            self::assertNull($connection->fetchOne('SELECT solved_post_id FROM forum_thread WHERE id = :id', ['id' => $threadId]));
            $gateway->setSubscription($threadId, $userId, false);
            self::assertFalse($gateway->isSubscribed($threadId, $userId));

            $gateway->saveRoom($publicRoom, 'Edited forum room '.$suffix, 'members', null);
            self::assertSame('members', $connection->fetchOne('SELECT visibility FROM forum_room WHERE id = :id', ['id' => $publicRoom]));
        } finally {
            foreach ($roomIds as $roomId) {
                $connection->delete('forum_room', ['id' => $roomId]);
            }
            $connection->delete('guild', ['id' => $guildOneId]);
            $connection->delete('guild', ['id' => $guildTwoId]);
            $connection->delete('game', ['id' => $gameId]);
            $entityManager->clear();
            $savedUser = $entityManager->find(User::class, $userId);
            if ($savedUser instanceof User) {
                $entityManager->remove($savedUser);
                $entityManager->flush();
            }
        }
    }

    private function expectStaleReply(ForumWorkflowGateway $gateway, int $threadId, int $userId): void
    {
        try {
            $gateway->reply($threadId, $userId, 'Stale answer', null, 0);
            self::fail('A stale thread version must be rejected.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('inzwischen geändert', $exception->getMessage());
        }
    }
}
