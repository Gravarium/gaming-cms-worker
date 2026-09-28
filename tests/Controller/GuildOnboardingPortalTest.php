<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Entity\Guild\GuildOnboardingTask;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class GuildOnboardingPortalTest extends WebTestCase
{
    public function testChecklistShowsOnlyTheUsersActiveGuildAssignmentsAndIsPrivate(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $previousGamingState = $this->setGamingEnabled($client, true);
        [$game, $guild, $otherGuild] = $this->guilds($client);
        $userIds = [];
        $guildIds = [$this->requiredId($guild->getId()), $this->requiredId($otherGuild->getId())];
        $gameId = $this->requiredId($game->getId());

        try {
            $memberUser = $this->user($client, 'Onboarding member');
            $otherUser = $this->user($client, 'Different member');
            $userIds = [$this->requiredId($memberUser->getId()), $this->requiredId($otherUser->getId())];
            $otherGuild->setEnabled(false);

            $active = (new GuildMember())->setGuild($guild)->setUser($memberUser)->setCharacterName('My active character');
            $inactive = (new GuildMember())->setGuild($guild)->setUser($memberUser)->setCharacterName('Inactive character')->setActive(false);
            $foreign = (new GuildMember())->setGuild($guild)->setUser($otherUser)->setCharacterName('Other user character');
            $disabledGuildMember = (new GuildMember())->setGuild($otherGuild)->setUser($memberUser)->setCharacterName('Disabled guild character');
            foreach ([$active, $inactive, $foreign, $disabledGuildMember] as $member) {
                $this->entityManager($client)->persist($member);
            }
            $tasks = [
                new GuildOnboardingTask($guild, $active, 'Read the guild welcome guide'),
                new GuildOnboardingTask($guild, $inactive, 'Inactive assignment canary'),
                new GuildOnboardingTask($guild, $foreign, 'Foreign assignment canary'),
                new GuildOnboardingTask($otherGuild, $disabledGuildMember, 'Disabled guild canary'),
            ];
            foreach ($tasks as $task) {
                $this->entityManager($client)->persist($task);
            }
            $this->entityManager($client)->flush();

            $client->loginUser($memberUser);
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/guild-area/onboarding"]');

            $client->request('GET', '/guild-area/onboarding');
            self::assertResponseIsSuccessful();
            self::assertSame('private, no-store', $client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex, nofollow, noarchive', $client->getResponse()->headers->get('X-Robots-Tag'));
            self::assertSelectorTextContains('body', 'Read the guild welcome guide');
            self::assertSelectorTextContains('body', 'My active character');
            self::assertSelectorNotExists('body:contains("Inactive assignment canary")');
            self::assertSelectorNotExists('body:contains("Foreign assignment canary")');
            self::assertSelectorNotExists('body:contains("Disabled guild canary")');
        } finally {
            $this->cleanup($client, $guildIds, $gameId, $userIds);
            $this->restoreGamingState($client, $previousGamingState);
        }
    }

    public function testMemberCompletesOwnTaskWithCsrfAndRepeatedCompletionPreservesEvidence(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $previousGamingState = $this->setGamingEnabled($client, true);
        [$game, $guild] = $this->guilds($client);
        $guildId = $this->requiredId($guild->getId());
        $gameId = $this->requiredId($game->getId());
        $userIds = [];

        try {
            $memberUser = $this->user($client, 'Checklist owner');
            $userIds[] = $this->requiredId($memberUser->getId());
            $member = (new GuildMember())->setGuild($guild)->setUser($memberUser)->setCharacterName('Checklist character');
            $task = new GuildOnboardingTask($guild, $member, 'Meet the guild officers');
            $this->entityManager($client)->persist($member);
            $this->entityManager($client)->persist($task);
            $this->entityManager($client)->flush();
            $taskId = $this->requiredId($task->getId());
            $userId = $this->requiredId($memberUser->getId());

            $client->loginUser($memberUser);
            $url = '/guild-area/onboarding/task/'.$taskId.'/complete';
            $crawler = $client->request('GET', '/guild-area/onboarding');
            self::assertResponseIsSuccessful();
            $token = $this->actionToken($crawler, $url);

            $client->request('POST', $url);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', $url, ['_token' => 'invalid-token']);
            self::assertResponseStatusCodeSame(403);
            self::assertFalse($task->isCompleted());

            $client->request('POST', $url, ['_token' => $token]);
            self::assertResponseRedirects('/guild-area/onboarding');
            $client->followRedirect();
            self::assertSelectorTextContains('body', 'Erledigt von Checklist owner');
            self::assertSelectorNotExists('form[action="'.$url.'"]');

            $first = $this->storedTask($client, $taskId);
            self::assertTrue((bool) $first['completed']);
            self::assertSame($userId, (int) $first['completed_by_id']);
            $firstCompletedAt = (string) $first['completed_at'];
            self::assertNotSame('', $firstCompletedAt);

            $client->request('POST', $url, ['_token' => $token]);
            self::assertResponseRedirects('/guild-area/onboarding');
            $client->followRedirect();
            self::assertSelectorTextContains('body', 'bereits erledigt');

            $second = $this->storedTask($client, $taskId);
            self::assertSame($userId, (int) $second['completed_by_id']);
            self::assertSame($firstCompletedAt, (string) $second['completed_at']);
        } finally {
            $this->cleanup($client, [$guildId], $gameId, $userIds);
            $this->restoreGamingState($client, $previousGamingState);
        }
    }

    public function testOtherUsersAndInactiveMembersCannotSeeAssignedTasks(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $previousGamingState = $this->setGamingEnabled($client, true);
        [$game, $guild] = $this->guilds($client);
        $guildId = $this->requiredId($guild->getId());
        $gameId = $this->requiredId($game->getId());
        $userIds = [];

        try {
            $viewer = $this->user($client, 'Checklist viewer');
            $owner = $this->user($client, 'Checklist task owner');
            $userIds = [$this->requiredId($viewer->getId()), $this->requiredId($owner->getId())];
            $ownedByOther = (new GuildMember())->setGuild($guild)->setUser($owner)->setCharacterName('Private character');
            $inactive = (new GuildMember())->setGuild($guild)->setUser($viewer)->setCharacterName('Retired character')->setActive(false);
            $privateTask = new GuildOnboardingTask($guild, $ownedByOther, 'Other account private task');
            $inactiveTask = new GuildOnboardingTask($guild, $inactive, 'Inactive character private task');
            foreach ([$ownedByOther, $inactive, $privateTask, $inactiveTask] as $entity) {
                $this->entityManager($client)->persist($entity);
            }
            $this->entityManager($client)->flush();
            $privateTaskId = $this->requiredId($privateTask->getId());

            $client->loginUser($viewer);
            $client->request('GET', '/guild-area/onboarding');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Keine Onboarding-Aufgaben vorhanden');
            self::assertSelectorNotExists('body:contains("Other account private task")');
            self::assertSelectorNotExists('body:contains("Inactive character private task")');

            $client->request('POST', '/guild-area/onboarding/task/'.$privateTaskId.'/complete');
            self::assertResponseStatusCodeSame(403);
            self::assertFalse($privateTask->isCompleted());
        } finally {
            $this->cleanup($client, [$guildId], $gameId, $userIds);
            $this->restoreGamingState($client, $previousGamingState);
        }
    }

    public function testPaginationIsStableBoundedAndRejectsMalformedPageValues(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $previousGamingState = $this->setGamingEnabled($client, true);
        [$game, $guild] = $this->guilds($client);
        $guildId = $this->requiredId($guild->getId());
        $gameId = $this->requiredId($game->getId());
        $userIds = [];

        try {
            $memberUser = $this->user($client, 'Pagination member');
            $userIds[] = $this->requiredId($memberUser->getId());
            $member = (new GuildMember())->setGuild($guild)->setUser($memberUser)->setCharacterName('Pagination character');
            $this->entityManager($client)->persist($member);
            for ($index = 0; $index < 27; ++$index) {
                $this->entityManager($client)->persist(new GuildOnboardingTask(
                    $guild,
                    $member,
                    sprintf('Checklist step %02d', $index),
                ));
            }
            $this->entityManager($client)->flush();

            $client->loginUser($memberUser);
            $crawler = $client->request('GET', '/guild-area/onboarding?page=1');
            self::assertResponseIsSuccessful();
            $firstPage = $this->labels($crawler);
            self::assertCount(25, $firstPage);

            $crawler = $client->request('GET', '/guild-area/onboarding?page=2');
            self::assertResponseIsSuccessful();
            $secondPage = $this->labels($crawler);
            self::assertCount(2, $secondPage);

            $allLabels = array_merge($firstPage, $secondPage);
            self::assertSame(array_map(static fn (int $index): string => sprintf('Checklist step %02d', $index), range(0, 26)), $allLabels);
            self::assertCount(27, array_unique($allLabels));

            $crawler = $client->request('GET', '/guild-area/onboarding?page=999');
            self::assertResponseIsSuccessful();
            self::assertSame($secondPage, $this->labels($crawler));

            foreach ([
                '/guild-area/onboarding?page=0',
                '/guild-area/onboarding?page=abc',
                '/guild-area/onboarding?page=1000001',
                '/guild-area/onboarding?page%5B%5D=1',
            ] as $url) {
                $client->request('GET', $url);
                self::assertResponseStatusCodeSame(400);
            }
        } finally {
            $this->cleanup($client, [$guildId], $gameId, $userIds);
            $this->restoreGamingState($client, $previousGamingState);
        }
    }

    public function testGamingModuleDisableHidesTheRouteAndPortalLink(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $previousGamingState = $this->setGamingEnabled($client, false);
        $userIds = [];

        try {
            $memberUser = $this->user($client, 'Module gate member');
            $userIds[] = $this->requiredId($memberUser->getId());
            $client->loginUser($memberUser);

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('a[href="/guild-area/onboarding"]');

            $client->request('GET', '/guild-area/onboarding');
            self::assertResponseStatusCodeSame(404);
            $client->request('POST', '/guild-area/onboarding/task/1/complete');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, [], null, $userIds);
            $this->restoreGamingState($client, $previousGamingState);
        }
    }

    /** @return array{Game, Guild, Guild} */
    private function guilds(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())->setName('Onboarding game '.$suffix)->setSlug('onboarding-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Onboarding guild '.$suffix)
            ->setSlug('onboarding-guild-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Synthetic onboarding test guild.');
        $otherGuild = (new Guild())
            ->setGame($game)
            ->setName('Other onboarding guild '.$suffix)
            ->setSlug('other-onboarding-guild-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Synthetic second guild.');
        $entityManager = $this->entityManager($client);
        $entityManager->persist($game);
        $entityManager->persist($guild);
        $entityManager->persist($otherGuild);
        $entityManager->flush();

        return [$game, $guild, $otherGuild];
    }

    private function user(KernelBrowser $client, string $displayName): User
    {
        $user = (new User())
            ->setEmail('guild-onboarding-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName($displayName)
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    /** @return array<string, mixed> */
    private function storedTask(KernelBrowser $client, int $taskId): array
    {
        $row = $this->entityManager($client)->getConnection()->fetchAssociative(
            'SELECT completed, completed_by_id, completed_at FROM guild_onboarding_task WHERE id = ?',
            [$taskId],
        );
        if (!is_array($row)) {
            throw new \LogicException('Expected the onboarding task to remain stored.');
        }

        return $row;
    }

    private function actionToken(Crawler $crawler, string $url): string
    {
        $token = $crawler->filter('form[action="'.$url.'"] input[name="_token"]')->attr('value');
        if ($token === null || $token === '') {
            throw new \LogicException('The onboarding task action did not render its CSRF token.');
        }

        return $token;
    }

    /** @return list<string> */
    private function labels(Crawler $crawler): array
    {
        return $crawler->filter('.application-card h2')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
    }

    /**
     * @param list<int> $guildIds
     * @param list<int> $userIds
     */
    private function cleanup(KernelBrowser $client, array $guildIds, ?int $gameId, array $userIds): void
    {
        $entityManager = $this->entityManager($client);
        foreach ($guildIds as $guildId) {
            $guild = $entityManager->find(Guild::class, $guildId);
            if (!$guild instanceof Guild) {
                continue;
            }
            foreach ($entityManager->getRepository(GuildOnboardingTask::class)->findBy(['guild' => $guild]) as $task) {
                $entityManager->remove($task);
            }
            foreach ($entityManager->getRepository(GuildMember::class)->findBy(['guild' => $guild]) as $member) {
                $entityManager->remove($member);
            }
            $entityManager->flush();
            $entityManager->remove($guild);
            $entityManager->flush();
        }

        if ($gameId !== null) {
            $game = $entityManager->find(Game::class, $gameId);
            if ($game instanceof Game) {
                $entityManager->remove($game);
            }
        }
        foreach ($userIds as $userId) {
            $user = $entityManager->find(User::class, $userId);
            if ($user instanceof User) {
                $entityManager->remove($user);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    private function setGamingEnabled(KernelBrowser $client, bool $enabled): ?bool
    {
        $entityManager = $this->entityManager($client);
        $state = $entityManager->getRepository(CmsModuleState::class)->find('gaming');
        $previous = $state instanceof CmsModuleState ? $state->isEnabled() : null;
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
        }
        $state->setEnabled($enabled);
        $entityManager->persist($state);
        $entityManager->flush();

        return $previous;
    }

    private function restoreGamingState(KernelBrowser $client, ?bool $previous): void
    {
        $entityManager = $this->entityManager($client);
        $state = $entityManager->getRepository(CmsModuleState::class)->find('gaming');
        if (!$state instanceof CmsModuleState) {
            return;
        }
        if ($previous === null) {
            $entityManager->remove($state);
        } else {
            $state->setEnabled($previous);
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    private function requiredId(?int $id): int
    {
        if ($id === null) {
            throw new \LogicException('Expected a persisted fixture ID.');
        }

        return $id;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
