<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\Guild;
use App\Entity\GuildTeam;
use App\Entity\User;
use App\Repository\GuildTeamRepository;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminGuildStructureSecurityTest extends WebTestCase
{
    public function testGuildTeamDeletionEnforcesPermissionCsrfParentIsolationAndTargetScope(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(6));
        $game = (new Game())
            ->setName('Security test game '.$suffix)
            ->setSlug('guild-security-'.$suffix);
        $ownerGuild = (new Guild())
            ->setGame($game)
            ->setName('Owner guild '.$suffix)
            ->setSlug('owner-guild-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Synthetic security fixture');
        $otherGuild = (new Guild())
            ->setGame($game)
            ->setName('Other guild '.$suffix)
            ->setSlug('other-guild-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Synthetic security fixture');
        $targetTeam = (new GuildTeam())
            ->setGuild($ownerGuild)
            ->setName('Target team '.$suffix);
        $siblingTeam = (new GuildTeam())
            ->setGuild($ownerGuild)
            ->setName('Sibling team '.$suffix);
        $unauthorized = $this->createUser('guild-team-denied-'.$suffix, []);
        $manager = $this->createUser('guild-team-manager-'.$suffix, [CmsPermission::GAMING]);

        $entityManager = $this->entityManager($client);
        foreach ([$game, $ownerGuild, $otherGuild, $targetTeam, $siblingTeam, $unauthorized, $manager] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $gameId = $game->getId();
        $ownerGuildId = $ownerGuild->getId();
        $otherGuildId = $otherGuild->getId();
        $targetTeamId = $targetTeam->getId();
        $siblingTeamId = $siblingTeam->getId();
        $unauthorizedId = $unauthorized->getId();
        $managerId = $manager->getId();
        self::assertNotNull($gameId);
        self::assertNotNull($ownerGuildId);
        self::assertNotNull($otherGuildId);
        self::assertNotNull($targetTeamId);
        self::assertNotNull($siblingTeamId);
        self::assertNotNull($unauthorizedId);
        self::assertNotNull($managerId);

        try {
            $targetUrl = sprintf('/admin/gaming/guild/%d/structure/team/%d/delete', $ownerGuildId, $targetTeamId);
            $client->loginUser($unauthorized);
            $client->request('POST', $targetUrl);
            self::assertResponseStatusCodeSame(403);
            self::assertInstanceOf(GuildTeam::class, $this->teams($client)->find($targetTeamId));

            $client->loginUser($manager);
            $client->request('POST', $targetUrl);
            self::assertResponseStatusCodeSame(403);
            self::assertInstanceOf(GuildTeam::class, $this->teams($client)->find($targetTeamId));

            $client->request('POST', $targetUrl, ['_token' => 'invalid-token']);
            self::assertResponseStatusCodeSame(403);
            self::assertInstanceOf(GuildTeam::class, $this->teams($client)->find($targetTeamId));

            $crawler = $client->request('GET', '/admin/gaming/guild/'.$ownerGuildId.'/structure');
            self::assertResponseIsSuccessful();
            $targetForm = $crawler->filterXPath('//form[@action="'.$targetUrl.'"]');
            self::assertCount(1, $targetForm);
            $values = $targetForm->form()->getPhpValues();
            self::assertNotEmpty($values['_token'] ?? null, 'Use the CSRF token rendered for the selected team.');

            $otherGuildUrl = sprintf('/admin/gaming/guild/%d/structure/team/%d/delete', $otherGuildId, $targetTeamId);
            $client->request('POST', $otherGuildUrl, $values);
            self::assertResponseStatusCodeSame(404);
            self::assertInstanceOf(GuildTeam::class, $this->teams($client)->find($targetTeamId));

            $client->request('POST', $targetUrl, $values);
            self::assertResponseRedirects('/admin/gaming/guild/'.$ownerGuildId.'/structure');

            $entityManager = $this->entityManager($client);
            $entityManager->clear();
            self::assertNull($this->teams($client)->find($targetTeamId));
            self::assertInstanceOf(GuildTeam::class, $this->teams($client)->find($siblingTeamId));
        } finally {
            $this->cleanupFixtures(
                $client,
                $targetTeamId,
                $siblingTeamId,
                $ownerGuildId,
                $otherGuildId,
                $gameId,
                $unauthorizedId,
                $managerId,
            );
        }
    }

    /** @param list<string> $permissions */
    private function createUser(string $label, array $permissions): User
    {
        return (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Synthetic '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash');
    }

    private function cleanupFixtures(
        KernelBrowser $client,
        ?int $targetTeamId,
        ?int $siblingTeamId,
        ?int $ownerGuildId,
        ?int $otherGuildId,
        ?int $gameId,
        ?int $unauthorizedId,
        ?int $managerId,
    ): void {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        foreach ([$targetTeamId, $siblingTeamId] as $teamId) {
            if ($teamId !== null) {
                $team = $entityManager->find(GuildTeam::class, $teamId);
                if ($team instanceof GuildTeam) {
                    $entityManager->remove($team);
                }
            }
        }

        foreach ([$ownerGuildId, $otherGuildId] as $guildId) {
            if ($guildId !== null) {
                $guild = $entityManager->find(Guild::class, $guildId);
                if ($guild instanceof Guild) {
                    $entityManager->remove($guild);
                }
            }
        }

        if ($gameId !== null) {
            $game = $entityManager->find(Game::class, $gameId);
            if ($game instanceof Game) {
                $entityManager->remove($game);
            }
        }

        foreach ([$unauthorizedId, $managerId] as $userId) {
            if ($userId !== null) {
                $user = $entityManager->find(User::class, $userId);
                if ($user instanceof User) {
                    $entityManager->remove($user);
                }
            }
        }

        $entityManager->flush();
    }

    private function teams(KernelBrowser $client): GuildTeamRepository
    {
        return $client->getContainer()->get(GuildTeamRepository::class);
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
