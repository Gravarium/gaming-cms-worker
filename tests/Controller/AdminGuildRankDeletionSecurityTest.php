<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildRank;
use App\Entity\User;
use App\Repository\GuildRankRepository;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminGuildRankDeletionSecurityTest extends WebTestCase
{
    public function testRankStructureAndDeletionRequireGamingManagementPermission(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(6));
        $fixtures = $this->createGuilds($client, $suffix);
        $rank = $this->createRank($client, $fixtures['guild'], 'Protected rank '.$suffix);
        $user = $this->createUser($client, 'denied-'.$suffix, [CmsPermission::CONTENT]);
        $rankId = $this->requireId($rank->getId());
        $userId = $this->requireId($user->getId());
        $guildId = $this->requireId($fixtures['guild']->getId());
        $ids = $this->fixtureIds($fixtures, $userId, [$rankId]);
        $client->loginUser($user);

        try {
            $structurePath = '/admin/gaming/guild/'.$guildId.'/structure';
            $deletePath = $structurePath.'/rank/'.$rankId.'/delete';

            $client->request('GET', $structurePath);
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', $deletePath);
            self::assertResponseStatusCodeSame(403);
            $this->assertRank($client, $rankId, $guildId, 'Protected rank '.$suffix);
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    public function testRankDeletionRequiresItsRenderedTokenAndOwningGuild(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(6));
        $fixtures = $this->createGuilds($client, $suffix);
        $target = $this->createRank($client, $fixtures['guild'], 'Target rank '.$suffix);
        $sibling = $this->createRank($client, $fixtures['guild'], 'Sibling rank '.$suffix);
        $foreign = $this->createRank($client, $fixtures['otherGuild'], 'Foreign rank '.$suffix);
        $manager = $this->createUser($client, 'manager-'.$suffix, [CmsPermission::GAMING]);
        $targetId = $this->requireId($target->getId());
        $siblingId = $this->requireId($sibling->getId());
        $foreignId = $this->requireId($foreign->getId());
        $managerId = $this->requireId($manager->getId());
        $guildId = $this->requireId($fixtures['guild']->getId());
        $otherGuildId = $this->requireId($fixtures['otherGuild']->getId());
        $ids = $this->fixtureIds($fixtures, $managerId, [$targetId, $siblingId, $foreignId]);
        $client->loginUser($manager);

        try {
            $structurePath = '/admin/gaming/guild/'.$guildId.'/structure';
            $targetPath = $structurePath.'/rank/'.$targetId.'/delete';
            $siblingPath = $structurePath.'/rank/'.$siblingId.'/delete';
            $targetToken = $this->renderedDeleteToken($client, $structurePath, $targetPath);
            $siblingToken = $this->renderedDeleteToken($client, $structurePath, $siblingPath);

            foreach ([[], ['_token' => 'invalid'], ['_token' => $siblingToken]] as $parameters) {
                $client->request('POST', $targetPath, $parameters);

                self::assertResponseStatusCodeSame(403);
                $this->assertRank($client, $targetId, $guildId, 'Target rank '.$suffix);
                $this->assertRank($client, $siblingId, $guildId, 'Sibling rank '.$suffix);
                $this->assertRank($client, $foreignId, $otherGuildId, 'Foreign rank '.$suffix);
            }

            $wrongGuildPath = '/admin/gaming/guild/'.$otherGuildId.'/structure/rank/'.$targetId.'/delete';
            $client->request('POST', $wrongGuildPath, ['_token' => $targetToken]);

            self::assertResponseStatusCodeSame(404);
            $this->assertRank($client, $targetId, $guildId, 'Target rank '.$suffix);
            $this->assertRank($client, $siblingId, $guildId, 'Sibling rank '.$suffix);
            $this->assertRank($client, $foreignId, $otherGuildId, 'Foreign rank '.$suffix);

            $client->request('POST', $targetPath, ['_token' => $targetToken]);

            self::assertResponseRedirects($structurePath);
            self::assertNull($this->findRank($client, $targetId));
            $this->assertRank($client, $siblingId, $guildId, 'Sibling rank '.$suffix);
            $this->assertRank($client, $foreignId, $otherGuildId, 'Foreign rank '.$suffix);
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    private function renderedDeleteToken(KernelBrowser $client, string $structurePath, string $deletePath): string
    {
        $crawler = $client->request('GET', $structurePath);
        self::assertResponseIsSuccessful();

        $token = (string) $crawler
            ->filter('form[action="'.$deletePath.'"] input[name="_token"]')
            ->attr('value');
        self::assertNotSame('', $token);

        return $token;
    }

    /**
     * @return array{game: Game, guild: Guild, otherGuild: Guild}
     */
    private function createGuilds(KernelBrowser $client, string $suffix): array
    {
        $game = (new Game())
            ->setName('Guild rank security game '.$suffix)
            ->setSlug('guild-rank-security-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Guild rank owner '.$suffix)
            ->setSlug('guild-rank-owner-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Synthetic rank deletion fixture');
        $otherGuild = (new Guild())
            ->setGame($game)
            ->setName('Guild rank other '.$suffix)
            ->setSlug('guild-rank-other-'.$suffix)
            ->setServerName('Other test server')
            ->setDescription('Other synthetic rank deletion fixture');

        $entityManager = $this->entityManager($client);
        $entityManager->persist($game);
        $entityManager->persist($guild);
        $entityManager->persist($otherGuild);
        $entityManager->flush();

        return ['game' => $game, 'guild' => $guild, 'otherGuild' => $otherGuild];
    }

    private function createRank(KernelBrowser $client, Guild $guild, string $name): GuildRank
    {
        $rank = (new GuildRank())
            ->setGuild($guild)
            ->setName($name)
            ->setPosition(0);
        $this->entityManager($client)->persist($rank);
        $this->entityManager($client)->flush();

        return $rank;
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('guild-rank-deletion-'.$label.'@example.test')
            ->setDisplayName('Guild rank deletion '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    /**
     * @param array{game: Game, guild: Guild, otherGuild: Guild} $fixtures
     * @param list<int> $rankIds
     * @return array{gameId: int, guildIds: list<int>, rankIds: list<int>, userId: int}
     */
    private function fixtureIds(array $fixtures, int $userId, array $rankIds): array
    {
        return [
            'gameId' => $this->requireId($fixtures['game']->getId()),
            'guildIds' => [
                $this->requireId($fixtures['guild']->getId()),
                $this->requireId($fixtures['otherGuild']->getId()),
            ],
            'rankIds' => $rankIds,
            'userId' => $userId,
        ];
    }

    private function findRank(KernelBrowser $client, int $rankId): ?GuildRank
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $rank = $entityManager->find(GuildRank::class, $rankId);

        return $rank instanceof GuildRank ? $rank : null;
    }

    private function assertRank(KernelBrowser $client, int $rankId, int $guildId, string $name): void
    {
        $rank = $this->findRank($client, $rankId);
        self::assertInstanceOf(GuildRank::class, $rank);
        self::assertSame($guildId, $rank->getGuild()?->getId());
        self::assertSame($name, $rank->getName());
    }

    /**
     * @param array{gameId: int, guildIds: list<int>, rankIds: list<int>, userId: int} $ids
     */
    private function cleanup(KernelBrowser $client, array $ids): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        foreach ($ids['rankIds'] as $rankId) {
            $rank = $entityManager->find(GuildRank::class, $rankId);
            if ($rank instanceof GuildRank) {
                $entityManager->remove($rank);
            }
        }

        $user = $entityManager->find(User::class, $ids['userId']);
        if ($user instanceof User) {
            $entityManager->remove($user);
        }

        foreach ($ids['guildIds'] as $guildId) {
            $guild = $entityManager->find(Guild::class, $guildId);
            if ($guild instanceof Guild) {
                $entityManager->remove($guild);
            }
        }

        $game = $entityManager->find(Game::class, $ids['gameId']);
        if ($game instanceof Game) {
            $entityManager->remove($game);
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
