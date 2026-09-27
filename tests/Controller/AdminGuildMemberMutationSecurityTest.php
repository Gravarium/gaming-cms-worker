<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminGuildMemberMutationSecurityTest extends WebTestCase
{
    public function testMemberCreationAndEditingRequireGamingManagementPermission(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'denied-'.bin2hex(random_bytes(5)), false);
        $fixtures = $this->createGuilds($client);
        $member = $this->createMember($client, $fixtures['guild'], 'Protected member');
        $userId = $this->requireId($user->getId());
        $guildId = $this->requireId($fixtures['guild']->getId());
        $memberId = $this->requireId($member->getId());
        $client->loginUser($user);

        try {
            $basePath = '/admin/gaming/guild/'.$guildId.'/members';
            $newPath = $basePath.'/new';
            $editPath = $basePath.'/'.$memberId.'/edit';

            $client->request('GET', $newPath);
            self::assertResponseStatusCodeSame(403);

            $client->request('GET', $editPath);
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', $newPath, [
                'guild_member' => [
                    'characterName' => 'Unauthorized new member',
                    'position' => '0',
                ],
            ]);
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', $editPath, [
                'guild_member' => [
                    'characterName' => 'Unauthorized edit',
                    'position' => '0',
                ],
            ]);
            self::assertResponseStatusCodeSame(403);

            $this->assertStoredMember($client, $memberId, $guildId, 'Protected member');
            self::assertNull($this->findMemberByCharacterName($client, 'Unauthorized new member'));
            self::assertNull($this->findMemberByCharacterName($client, 'Unauthorized edit'));
        } finally {
            $this->cleanup($client, $userId, $fixtures);
        }
    }

    public function testCreateAndEditRequireRenderedCsrfAndCannotCrossGuildOwnership(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'allowed-'.bin2hex(random_bytes(5)), true);
        $fixtures = $this->createGuilds($client);
        $target = $this->createMember($client, $fixtures['guild'], 'Original target');
        $sibling = $this->createMember($client, $fixtures['guild'], 'Unchanged sibling');
        $foreign = $this->createMember($client, $fixtures['otherGuild'], 'Foreign member');
        $userId = $this->requireId($user->getId());
        $guildId = $this->requireId($fixtures['guild']->getId());
        $otherGuildId = $this->requireId($fixtures['otherGuild']->getId());
        $targetId = $this->requireId($target->getId());
        $siblingId = $this->requireId($sibling->getId());
        $foreignId = $this->requireId($foreign->getId());
        $client->loginUser($user);

        try {
            $basePath = '/admin/gaming/guild/'.$guildId.'/members';
            $otherBasePath = '/admin/gaming/guild/'.$otherGuildId.'/members';
            $newPath = $basePath.'/new';
            $editPath = $basePath.'/'.$targetId.'/edit';

            foreach ([
                ['mode' => 'missing', 'name' => 'Rejected create missing '.bin2hex(random_bytes(4))],
                ['mode' => 'invalid', 'name' => 'Rejected create invalid '.bin2hex(random_bytes(4))],
            ] as $case) {
                $values = $this->renderedFormValues($client, $newPath, $case['name'], $case['mode']);
                $client->request('POST', $newPath, $values);

                self::assertResponseIsSuccessful();
                self::assertNull($this->findMemberByCharacterName($client, $case['name']));
            }

            $createdName = 'Created with rendered token '.bin2hex(random_bytes(5));
            $values = $this->renderedFormValues($client, $newPath, $createdName, 'valid');
            $client->request('POST', $newPath, $values);

            self::assertResponseRedirects($basePath);
            $created = $this->findMemberByCharacterName($client, $createdName);
            self::assertInstanceOf(GuildMember::class, $created);
            $createdId = $this->requireId($created->getId());
            self::assertSame($guildId, $created->getGuild()?->getId());

            foreach ([
                ['mode' => 'missing', 'name' => 'Rejected edit missing '.bin2hex(random_bytes(4))],
                ['mode' => 'invalid', 'name' => 'Rejected edit invalid '.bin2hex(random_bytes(4))],
            ] as $case) {
                $values = $this->renderedFormValues($client, $editPath, $case['name'], $case['mode']);
                $client->request('POST', $editPath, $values);

                self::assertResponseIsSuccessful();
                $this->assertStoredMember($client, $targetId, $guildId, 'Original target');
                self::assertNull($this->findMemberByCharacterName($client, $case['name']));
            }

            $editedName = 'Edited with rendered token '.bin2hex(random_bytes(5));
            $values = $this->renderedFormValues($client, $editPath, $editedName, 'valid');
            $client->request('POST', $editPath, $values);

            self::assertResponseRedirects($basePath);
            $this->assertStoredMember($client, $targetId, $guildId, $editedName);
            $this->assertStoredMember($client, $siblingId, $guildId, 'Unchanged sibling');
            $this->assertStoredMember($client, $createdId, $guildId, $createdName);
            $this->assertStoredMember($client, $foreignId, $otherGuildId, 'Foreign member');

            $wrongGuildPath = $basePath.'/'.$foreignId.'/edit';
            $client->request('GET', $wrongGuildPath);
            self::assertResponseStatusCodeSame(404);
            $this->assertStoredMember($client, $foreignId, $otherGuildId, 'Foreign member');

            $foreignEditPath = $otherBasePath.'/'.$foreignId.'/edit';
            $crossGuildName = 'Cross-guild edit attempt '.bin2hex(random_bytes(5));
            $values = $this->renderedFormValues($client, $foreignEditPath, $crossGuildName, 'valid');
            $client->request('POST', $wrongGuildPath, $values);

            self::assertResponseStatusCodeSame(404);
            $this->assertStoredMember($client, $foreignId, $otherGuildId, 'Foreign member');
            $this->assertStoredMember($client, $targetId, $guildId, $editedName);
            $this->assertStoredMember($client, $siblingId, $guildId, 'Unchanged sibling');
            self::assertNull($this->findMemberByCharacterName($client, $crossGuildName));
        } finally {
            $this->cleanup($client, $userId, $fixtures);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function renderedFormValues(
        KernelBrowser $client,
        string $path,
        string $characterName,
        string $csrfMode,
    ): array {
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Mitglied speichern')->form();
        $formName = $form->getName();
        self::assertNotSame('', $formName);

        $values = $form->getPhpValues();
        $formValues = $values[$formName] ?? null;
        self::assertIsArray($formValues);
        self::assertArrayHasKey('_token', $formValues);
        self::assertNotSame('', (string) $formValues['_token']);
        $formValues['characterName'] = $characterName;
        $formValues['position'] = '0';

        if ($csrfMode === 'missing') {
            unset($formValues['_token']);
        } elseif ($csrfMode === 'invalid') {
            $formValues['_token'] = 'invalid';
        }

        $values[$formName] = $formValues;

        return $values;
    }

    private function createUser(KernelBrowser $client, string $label, bool $withGamingPermission): User
    {
        $permissions = [CmsPermission::ACCESS];
        if ($withGamingPermission) {
            $permissions[] = CmsPermission::GAMING;
        }

        $user = (new User())
            ->setEmail('guild-member-mutation-'.$label.'@example.test')
            ->setDisplayName('Guild member mutation '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    /**
     * @return array{game: Game, guild: Guild, otherGuild: Guild}
     */
    private function createGuilds(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(6));
        $game = (new Game())
            ->setName('Guild member mutation game '.$suffix)
            ->setSlug('guild-member-mutation-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Guild member mutation guild '.$suffix)
            ->setSlug('guild-member-mutation-guild-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Guild member mutation security fixture');
        $otherGuild = (new Guild())
            ->setGame($game)
            ->setName('Other mutation guild '.$suffix)
            ->setSlug('other-guild-member-mutation-'.$suffix)
            ->setServerName('Other test server')
            ->setDescription('Other guild member mutation security fixture');

        $entityManager = $this->entityManager($client);
        $entityManager->persist($game);
        $entityManager->persist($guild);
        $entityManager->persist($otherGuild);
        $entityManager->flush();

        return ['game' => $game, 'guild' => $guild, 'otherGuild' => $otherGuild];
    }

    private function createMember(KernelBrowser $client, Guild $guild, string $name): GuildMember
    {
        $member = (new GuildMember())
            ->setGuild($guild)
            ->setCharacterName($name)
            ->setPosition(0)
            ->setActive(true);
        $this->entityManager($client)->persist($member);
        $this->entityManager($client)->flush();

        return $member;
    }

    private function findMemberByCharacterName(KernelBrowser $client, string $name): ?GuildMember
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $member = $entityManager->getRepository(GuildMember::class)->findOneBy(['characterName' => $name]);

        return $member instanceof GuildMember ? $member : null;
    }

    private function assertStoredMember(KernelBrowser $client, int $memberId, int $guildId, string $name): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $member = $entityManager->find(GuildMember::class, $memberId);

        self::assertInstanceOf(GuildMember::class, $member);
        self::assertSame($guildId, $member->getGuild()?->getId());
        self::assertSame($name, $member->getCharacterName());
    }

    /**
     * @param array{game: Game, guild: Guild, otherGuild: Guild} $fixtures
     */
    private function cleanup(KernelBrowser $client, int $userId, array $fixtures): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        foreach ([$fixtures['guild'], $fixtures['otherGuild']] as $guildFixture) {
            $guildId = $guildFixture->getId();
            if ($guildId === null) {
                continue;
            }

            $guild = $entityManager->find(Guild::class, $guildId);
            if (!$guild instanceof Guild) {
                continue;
            }

            foreach ($entityManager->getRepository(GuildMember::class)->findBy(['guild' => $guild]) as $member) {
                if ($member instanceof GuildMember) {
                    $entityManager->remove($member);
                }
            }
        }

        $user = $entityManager->find(User::class, $userId);
        if ($user instanceof User) {
            $entityManager->remove($user);
        }

        foreach ([$fixtures['guild'], $fixtures['otherGuild']] as $guildFixture) {
            $guildId = $guildFixture->getId();
            if ($guildId === null) {
                continue;
            }

            $guild = $entityManager->find(Guild::class, $guildId);
            if ($guild instanceof Guild) {
                $entityManager->remove($guild);
            }
        }

        $gameId = $fixtures['game']->getId();
        if ($gameId !== null) {
            $game = $entityManager->find(Game::class, $gameId);
            if ($game instanceof Game) {
                $entityManager->remove($game);
            }
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
