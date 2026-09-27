<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildRank;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminGuildRankDefaultSelectionTest extends WebTestCase
{
    public function testEditingRankAsDefaultClearsOnlyThePreviousDefaultInItsGuild(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(6));
        $fixtures = $this->createGuilds($client, $suffix);
        $existingDefault = $this->createRank($client, $fixtures['guild'], 'Existing default '.$suffix, true);
        $promoted = $this->createRank($client, $fixtures['guild'], 'Promoted rank '.$suffix, false);
        $foreignDefault = $this->createRank($client, $fixtures['otherGuild'], 'Foreign default '.$suffix, true);
        $manager = $this->createUser($client, 'edit-'.$suffix);
        $managerId = $this->requireId($manager->getId());
        $existingId = $this->requireId($existingDefault->getId());
        $promotedId = $this->requireId($promoted->getId());
        $foreignId = $this->requireId($foreignDefault->getId());
        $guildId = $this->requireId($fixtures['guild']->getId());
        $otherGuildId = $this->requireId($fixtures['otherGuild']->getId());
        $ids = $this->fixtureIds($fixtures, $managerId);
        $client->loginUser($manager);

        try {
            $structurePath = '/admin/gaming/guild/'.$guildId.'/structure';
            $editPath = $structurePath.'/rank/'.$promotedId.'/edit';
            $values = $this->renderedDefaultRankFormValues($client, $editPath, 'Promoted rank '.$suffix);
            $client->request('POST', $editPath, $values);

            self::assertResponseRedirects($structurePath);
            $this->assertRankDefault($client, $promotedId, true);
            $this->assertRankDefault($client, $existingId, false);
            $this->assertRankDefault($client, $foreignId, true);
            self::assertSame(1, $this->defaultRankCount($client, $guildId));
            self::assertSame(1, $this->defaultRankCount($client, $otherGuildId));
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    public function testCreatingDefaultRankReplacesOnlyItsGuildDefault(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(6));
        $fixtures = $this->createGuilds($client, $suffix);
        $existingDefault = $this->createRank($client, $fixtures['guild'], 'Existing default '.$suffix, true);
        $foreignDefault = $this->createRank($client, $fixtures['otherGuild'], 'Foreign default '.$suffix, true);
        $manager = $this->createUser($client, 'create-'.$suffix);
        $managerId = $this->requireId($manager->getId());
        $existingId = $this->requireId($existingDefault->getId());
        $foreignId = $this->requireId($foreignDefault->getId());
        $guildId = $this->requireId($fixtures['guild']->getId());
        $otherGuildId = $this->requireId($fixtures['otherGuild']->getId());
        $ids = $this->fixtureIds($fixtures, $managerId);
        $client->loginUser($manager);

        try {
            $structurePath = '/admin/gaming/guild/'.$guildId.'/structure';
            $newPath = $structurePath.'/rank/new';
            $newName = 'New default '.$suffix;
            $values = $this->renderedDefaultRankFormValues($client, $newPath, $newName);
            $client->request('POST', $newPath, $values);

            self::assertResponseRedirects($structurePath);
            $created = $this->findRankByName($client, $guildId, $newName);
            self::assertInstanceOf(GuildRank::class, $created);
            $createdId = $this->requireId($created->getId());

            $this->assertRankDefault($client, $createdId, true);
            $this->assertRankDefault($client, $existingId, false);
            $this->assertRankDefault($client, $foreignId, true);
            self::assertSame(1, $this->defaultRankCount($client, $guildId));
            self::assertSame(1, $this->defaultRankCount($client, $otherGuildId));
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function renderedDefaultRankFormValues(KernelBrowser $client, string $path, string $name): array
    {
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Speichern')->form();
        $formName = $form->getName();
        self::assertNotSame('', $formName);

        $values = $form->getPhpValues();
        $formValues = $values[$formName] ?? null;
        self::assertIsArray($formValues);
        self::assertArrayHasKey('_token', $formValues);
        self::assertNotSame('', (string) $formValues['_token']);
        $formValues['name'] = $name;
        $formValues['position'] = '0';
        $formValues['defaultRank'] = '1';
        $formValues['enabled'] = '1';
        $values[$formName] = $formValues;

        return $values;
    }

    /**
     * @return array{game: Game, guild: Guild, otherGuild: Guild}
     */
    private function createGuilds(KernelBrowser $client, string $suffix): array
    {
        $game = (new Game())
            ->setName('Guild rank default game '.$suffix)
            ->setSlug('guild-rank-default-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Guild rank default owner '.$suffix)
            ->setSlug('guild-rank-default-owner-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Synthetic default-rank invariant fixture');
        $otherGuild = (new Guild())
            ->setGame($game)
            ->setName('Other rank default guild '.$suffix)
            ->setSlug('other-rank-default-guild-'.$suffix)
            ->setServerName('Other test server')
            ->setDescription('Other synthetic default-rank invariant fixture');

        $entityManager = $this->entityManager($client);
        $entityManager->persist($game);
        $entityManager->persist($guild);
        $entityManager->persist($otherGuild);
        $entityManager->flush();

        return ['game' => $game, 'guild' => $guild, 'otherGuild' => $otherGuild];
    }

    private function createRank(KernelBrowser $client, Guild $guild, string $name, bool $default): GuildRank
    {
        $rank = (new GuildRank())
            ->setGuild($guild)
            ->setName($name)
            ->setPosition(0)
            ->setDefaultRank($default);
        $this->entityManager($client)->persist($rank);
        $this->entityManager($client)->flush();

        return $rank;
    }

    private function createUser(KernelBrowser $client, string $label): User
    {
        $user = (new User())
            ->setEmail('guild-rank-default-'.$label.'@example.test')
            ->setDisplayName('Guild rank default '.$label)
            ->setPermissions([CmsPermission::GAMING])
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    /**
     * @param array{game: Game, guild: Guild, otherGuild: Guild} $fixtures
     * @return array{gameId: int, guildIds: list<int>, userId: int}
     */
    private function fixtureIds(array $fixtures, int $userId): array
    {
        return [
            'gameId' => $this->requireId($fixtures['game']->getId()),
            'guildIds' => [
                $this->requireId($fixtures['guild']->getId()),
                $this->requireId($fixtures['otherGuild']->getId()),
            ],
            'userId' => $userId,
        ];
    }

    private function findRankByName(KernelBrowser $client, int $guildId, string $name): ?GuildRank
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $guild = $entityManager->find(Guild::class, $guildId);
        self::assertInstanceOf(Guild::class, $guild);
        $rank = $entityManager->getRepository(GuildRank::class)->findOneBy(['guild' => $guild, 'name' => $name]);

        return $rank instanceof GuildRank ? $rank : null;
    }

    private function assertRankDefault(KernelBrowser $client, int $rankId, bool $expected): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $rank = $entityManager->find(GuildRank::class, $rankId);

        self::assertInstanceOf(GuildRank::class, $rank);
        self::assertSame($expected, $rank->isDefaultRank());
    }

    private function defaultRankCount(KernelBrowser $client, int $guildId): int
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $guild = $entityManager->find(Guild::class, $guildId);
        self::assertInstanceOf(Guild::class, $guild);

        return count($entityManager->getRepository(GuildRank::class)->findBy(['guild' => $guild, 'defaultRank' => true]));
    }

    /**
     * @param array{gameId: int, guildIds: list<int>, userId: int} $ids
     */
    private function cleanup(KernelBrowser $client, array $ids): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        foreach ($ids['guildIds'] as $guildId) {
            $guild = $entityManager->find(Guild::class, $guildId);
            if (!$guild instanceof Guild) {
                continue;
            }

            foreach ($entityManager->getRepository(GuildRank::class)->findBy(['guild' => $guild]) as $rank) {
                if ($rank instanceof GuildRank) {
                    $entityManager->remove($rank);
                }
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
