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

final class AdminGuildMemberDeletionSecurityTest extends WebTestCase
{
    public function testRosterAndDeletionRequireGamingManagementPermission(): void
    {
        $auth = $this->authenticatedClient(false);
        $client = $auth['client'];
        $fixtures = $this->createGuilds($client);
        $member = $this->createMember($client, $fixtures['guild'], 'denied');
        $memberId = $this->requireId($member->getId());
        $ids = $this->emptyCleanupIds($auth['userId'], $fixtures);
        $ids['memberIds'][] = $memberId;

        try {
            $base = '/admin/gaming/guild/'.$fixtures['guild']->getId().'/members';
            $client->request('GET', $base);
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', $base.'/'.$memberId.'/delete');
            self::assertResponseStatusCodeSame(403);
            self::assertTrue($this->memberExists($client, $memberId));
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    public function testDeletionRequiresTheRenderedMemberTokenAndMatchingGuild(): void
    {
        $auth = $this->authenticatedClient();
        $client = $auth['client'];
        $fixtures = $this->createGuilds($client);
        $target = $this->createMember($client, $fixtures['guild'], 'target');
        $sibling = $this->createMember($client, $fixtures['guild'], 'sibling');
        $foreign = $this->createMember($client, $fixtures['otherGuild'], 'foreign');
        $targetId = $this->requireId($target->getId());
        $siblingId = $this->requireId($sibling->getId());
        $foreignId = $this->requireId($foreign->getId());
        $ids = $this->emptyCleanupIds($auth['userId'], $fixtures);
        $ids['memberIds'] = [$targetId, $siblingId, $foreignId];

        try {
            $guildPath = '/admin/gaming/guild/'.$fixtures['guild']->getId().'/members';
            $targetPath = $guildPath.'/'.$targetId.'/delete';
            $siblingPath = $guildPath.'/'.$siblingId.'/delete';
            $targetToken = $this->renderedDeleteToken($client, $guildPath, $targetPath);
            $siblingToken = $this->renderedDeleteToken($client, $guildPath, $siblingPath);

            foreach ([[], ['_token' => 'invalid'], ['_token' => $siblingToken]] as $parameters) {
                $client->request('POST', $targetPath, $parameters);

                self::assertResponseStatusCodeSame(403);
                self::assertTrue($this->memberExists($client, $targetId));
                self::assertTrue($this->memberExists($client, $siblingId));
                self::assertTrue($this->memberExists($client, $foreignId));
            }

            $otherGuildPath = '/admin/gaming/guild/'.$fixtures['otherGuild']->getId().'/members';
            $foreignPath = $guildPath.'/'.$foreignId.'/delete';
            $foreignToken = $this->renderedDeleteToken(
                $client,
                $otherGuildPath,
                $otherGuildPath.'/'.$foreignId.'/delete',
            );
            $client->request('POST', $foreignPath, ['_token' => $foreignToken]);

            self::assertResponseStatusCodeSame(404);
            self::assertTrue($this->memberExists($client, $targetId));
            self::assertTrue($this->memberExists($client, $siblingId));
            self::assertTrue($this->memberExists($client, $foreignId));

            $client->request('POST', $targetPath, ['_token' => $targetToken]);

            self::assertResponseRedirects($guildPath);
            self::assertFalse($this->memberExists($client, $targetId));
            self::assertTrue($this->memberExists($client, $siblingId));
            self::assertTrue($this->memberExists($client, $foreignId));
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    /**
     * @return array{client: KernelBrowser, user: User, userId: int}
     */
    private function authenticatedClient(bool $withGamingPermission = true): array
    {
        $client = static::createClient();
        $permissions = [CmsPermission::ACCESS];
        if ($withGamingPermission) {
            $permissions[] = CmsPermission::GAMING;
        }

        $user = (new User())
            ->setEmail('guild-member-delete-'.bin2hex(random_bytes(7)).'@example.test')
            ->setDisplayName('Guild member deletion security')
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();
        $userId = $this->requireId($user->getId());
        $client->loginUser($user);

        return ['client' => $client, 'user' => $user, 'userId' => $userId];
    }

    /**
     * @return array{game: Game, guild: Guild, otherGuild: Guild}
     */
    private function createGuilds(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(6));
        $game = (new Game())
            ->setName('Guild member deletion game '.$suffix)
            ->setSlug('guild-member-deletion-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Guild member deletion guild '.$suffix)
            ->setSlug('guild-member-deletion-guild-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Guild member deletion security fixture');
        $otherGuild = (new Guild())
            ->setGame($game)
            ->setName('Other deletion guild '.$suffix)
            ->setSlug('other-member-deletion-guild-'.$suffix)
            ->setServerName('Other test server')
            ->setDescription('Other guild deletion security fixture');

        $entityManager = $this->em($client);
        $entityManager->persist($game);
        $entityManager->persist($guild);
        $entityManager->persist($otherGuild);
        $entityManager->flush();

        return ['game' => $game, 'guild' => $guild, 'otherGuild' => $otherGuild];
    }

    private function createMember(KernelBrowser $client, Guild $guild, string $label): GuildMember
    {
        $member = (new GuildMember())
            ->setGuild($guild)
            ->setCharacterName('Deletion '.$label.' '.bin2hex(random_bytes(4)))
            ->setActive(true);
        $this->em($client)->persist($member);
        $this->em($client)->flush();

        return $member;
    }

    private function renderedDeleteToken(KernelBrowser $client, string $listPath, string $deletePath): string
    {
        $crawler = $client->request('GET', $listPath);
        self::assertResponseIsSuccessful();

        $token = (string) $crawler
            ->filter('form[action="'.$deletePath.'"] input[name="_token"]')
            ->attr('value');
        self::assertNotSame('', $token);

        return $token;
    }

    private function memberExists(KernelBrowser $client, int $memberId): bool
    {
        $entityManager = $this->em($client);
        $entityManager->clear();

        return $entityManager->find(GuildMember::class, $memberId) instanceof GuildMember;
    }

    /**
     * @param array{game: Game, guild: Guild, otherGuild: Guild} $fixtures
     * @return array{memberIds: list<int>, userIds: list<int>, guildIds: list<int>, gameIds: list<int>}
     */
    private function emptyCleanupIds(int $userId, array $fixtures): array
    {
        return [
            'memberIds' => [],
            'userIds' => [$userId],
            'guildIds' => [$this->requireId($fixtures['guild']->getId()), $this->requireId($fixtures['otherGuild']->getId())],
            'gameIds' => [$this->requireId($fixtures['game']->getId())],
        ];
    }

    /**
     * @param array{memberIds: list<int>, userIds: list<int>, guildIds: list<int>, gameIds: list<int>} $ids
     */
    private function cleanup(KernelBrowser $client, array $ids): void
    {
        $entityManager = $this->em($client);
        $entityManager->clear();

        $this->removeEntities($entityManager, GuildMember::class, $ids['memberIds']);
        $this->removeEntities($entityManager, User::class, $ids['userIds']);
        $this->removeEntities($entityManager, Guild::class, $ids['guildIds']);
        $this->removeEntities($entityManager, Game::class, $ids['gameIds']);
        $entityManager->flush();
    }

    /**
     * @param class-string $class
     * @param list<int> $ids
     */
    private function removeEntities(EntityManagerInterface $entityManager, string $class, array $ids): void
    {
        foreach ($ids as $id) {
            $entity = $entityManager->find($class, $id);
            if ($entity !== null) {
                $entityManager->remove($entity);
            }
        }
    }

    private function requireId(?int $id): int
    {
        if ($id === null) {
            throw new LogicException('Persisted fixture has no identifier.');
        }

        return $id;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
