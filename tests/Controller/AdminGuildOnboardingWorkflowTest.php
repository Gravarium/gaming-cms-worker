<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Entity\Guild\GuildOnboardingTask;
use App\Entity\User;
use App\Repository\GuildOnboardingTaskReadRepository;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminGuildOnboardingWorkflowTest extends WebTestCase
{
    public function testChecklistIsDiscoverableAndRejectsInactiveOrForeignMembers(): void
    {
        $client = static::createClient();
        [$game, $guild, $otherGuild] = $this->guilds($client);
        $guildId = $this->requiredId($guild->getId());
        $otherGuildId = $this->requiredId($otherGuild->getId());
        $gameId = $this->requiredId($game->getId());
        $userIds = [];

        try {
            $manager = $this->user($client, 'Onboarding manager', [CmsPermission::GAMING]);
            $userIds[] = $this->requiredId($manager->getId());

            $active = (new GuildMember())->setGuild($guild)->setCharacterName('Active hero');
            $inactive = (new GuildMember())->setGuild($guild)->setCharacterName('Inactive hero')->setActive(false);
            $foreign = (new GuildMember())->setGuild($otherGuild)->setCharacterName('Foreign hero');
            foreach ([$active, $inactive, $foreign] as $member) {
                $this->em($client)->persist($member);
            }
            $this->em($client)->flush();
            $activeId = $this->requiredId($active->getId());
            $inactiveId = $this->requiredId($inactive->getId());
            $foreignId = $this->requiredId($foreign->getId());

            $client->loginUser($manager);
            $editUrl = '/admin/gaming/guild/'.$guildId.'/edit';
            $client->request('GET', $editUrl);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/admin/gaming/guild/'.$guildId.'/onboarding"]');

            $newUrl = '/admin/gaming/guild/'.$guildId.'/onboarding/new';
            $crawler = $client->request('GET', $newUrl);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('select[name="guild_onboarding_task[member]"] option[value="'.$activeId.'"]');
            self::assertSame(0, $crawler->filter('select[name="guild_onboarding_task[member]"] option[value="'.$inactiveId.'"]')->count());
            self::assertSame(0, $crawler->filter('select[name="guild_onboarding_task[member]"] option[value="'.$foreignId.'"]')->count());
            $token = $this->formToken($crawler, 'guild_onboarding_task[_token]');

            $client->request('POST', $newUrl, [
                'guild_onboarding_task' => [
                    'label' => 'Missing CSRF',
                    'member' => (string) $activeId,
                ],
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(0, $this->em($client)->getRepository(GuildOnboardingTask::class)->count(['guild' => $guild]));

            foreach ([$inactiveId, $foreignId] as $rejectedMemberId) {
                $client->request('POST', $newUrl, [
                    'guild_onboarding_task' => [
                        '_token' => $token,
                        'label' => 'Must not be assigned',
                        'member' => (string) $rejectedMemberId,
                    ],
                ]);
                self::assertResponseStatusCodeSame(422);
                self::assertSame(0, $this->em($client)->getRepository(GuildOnboardingTask::class)->count(['guild' => $guild]));
            }

            $crawler = $client->request('GET', $newUrl);
            $form = $crawler->selectButton('Aufgabe hinzufügen')->form([
                'guild_onboarding_task[label]' => 'Willkommen lesen',
                'guild_onboarding_task[member]' => (string) $activeId,
            ]);
            $client->submit($form);
            self::assertResponseRedirects('/admin/gaming/guild/'.$guildId.'/onboarding');
            $crawler = $client->followRedirect();
            self::assertSelectorTextContains('body', 'Willkommen lesen');
            self::assertSelectorTextContains('body', 'Active hero');

            $client->request('GET', '/admin/gaming/guild/'.$otherGuildId.'/onboarding');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextNotContains('body', 'Willkommen lesen');
            self::assertSame(1, $this->em($client)->getRepository(GuildOnboardingTask::class)->count(['guild' => $guild]));
            self::assertSame(0, $this->em($client)->getRepository(GuildOnboardingTask::class)->count(['guild' => $otherGuild]));
        } finally {
            $this->cleanup($client, [$guildId, $otherGuildId], $gameId, $userIds);
        }
    }

    public function testCompletionEvidenceIsImmutableAndCompletedTasksCannotBeDeleted(): void
    {
        $client = static::createClient();
        [$game, $guild] = $this->guilds($client);
        $guildId = $this->requiredId($guild->getId());
        $gameId = $this->requiredId($game->getId());
        $userIds = [];

        try {
            $manager = $this->user($client, 'Guild officer', [CmsPermission::GAMING]);
            $userIds[] = $this->requiredId($manager->getId());
            $member = (new GuildMember())->setGuild($guild)->setCharacterName('New recruit');
            $task = new GuildOnboardingTask($guild, $member, 'Read the guild guide');
            $this->em($client)->persist($member);
            $this->em($client)->persist($task);
            $this->em($client)->flush();
            $taskId = $this->requiredId($task->getId());

            $client->loginUser($manager);
            $completeUrl = '/admin/gaming/guild/'.$guildId.'/onboarding/task/'.$taskId.'/complete';
            $deleteUrl = '/admin/gaming/guild/'.$guildId.'/onboarding/task/'.$taskId.'/delete';
            $crawler = $client->request('GET', '/admin/gaming/guild/'.$guildId.'/onboarding');
            self::assertResponseIsSuccessful();
            $completeToken = $this->actionToken($crawler, $completeUrl);
            $deleteToken = $this->actionToken($crawler, $deleteUrl);
            $client->request('POST', $completeUrl);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', $completeUrl, ['_token' => 'invalid-token']);
            self::assertResponseStatusCodeSame(403);
            self::assertFalse($task->isCompleted());

            $client->request('POST', $completeUrl, [
                '_token' => $completeToken,
            ]);
            self::assertResponseRedirects('/admin/gaming/guild/'.$guildId.'/onboarding');
            $client->followRedirect();
            self::assertSelectorTextContains('body', 'Guild officer');
            self::assertSelectorTextNotContains('body', 'Als erledigt markieren');

            $first = $this->taskRows($client, $guild)[0] ?? null;
            self::assertIsArray($first);
            self::assertTrue((bool) $first['completed']);
            self::assertSame('Guild officer', $first['completedBy']);
            self::assertNotNull($first['completedAt']);
            self::assertNotSame('', $first['completedAt']);
            $firstCompletedAt = $first['completedAt'];

            $client->request('POST', $completeUrl, [
                '_token' => $completeToken,
            ]);
            self::assertResponseRedirects('/admin/gaming/guild/'.$guildId.'/onboarding');
            $client->followRedirect();
            self::assertSelectorTextContains('body', 'bereits erledigt');
            $second = $this->taskRows($client, $guild)[0] ?? null;
            self::assertIsArray($second);
            self::assertSame('Guild officer', $second['completedBy']);
            self::assertEquals($firstCompletedAt, $second['completedAt']);

            $deleteUrl = '/admin/gaming/guild/'.$guildId.'/onboarding/task/'.$taskId.'/delete';
            $client->request('POST', $deleteUrl, [
                '_token' => $deleteToken,
            ]);
            self::assertResponseRedirects('/admin/gaming/guild/'.$guildId.'/onboarding');
            $client->followRedirect();
            self::assertSelectorTextContains('body', 'bleiben als Nachweis erhalten');
            self::assertSame(1, $this->em($client)->getRepository(GuildOnboardingTask::class)->count(['guild' => $guild]));
        } finally {
            $this->cleanup($client, [$guildId], $gameId, $userIds);
        }
    }

    public function testTaskDeletionRequiresCsrfAndForeignTaskIdsAreHidden(): void
    {
        $client = static::createClient();
        [$game, $guild, $otherGuild] = $this->guilds($client);
        $guildId = $this->requiredId($guild->getId());
        $otherGuildId = $this->requiredId($otherGuild->getId());
        $gameId = $this->requiredId($game->getId());
        $userIds = [];

        try {
            $manager = $this->user($client, 'Guild manager', [CmsPermission::GAMING]);
            $userIds[] = $this->requiredId($manager->getId());
            $member = (new GuildMember())->setGuild($guild)->setCharacterName('Local member');
            $foreignMember = (new GuildMember())->setGuild($otherGuild)->setCharacterName('Foreign member');
            $localTask = new GuildOnboardingTask($guild, $member, 'Local checklist item');
            $foreignTask = new GuildOnboardingTask($otherGuild, $foreignMember, 'Foreign checklist item');
            foreach ([$member, $foreignMember, $localTask, $foreignTask] as $entity) {
                $this->em($client)->persist($entity);
            }
            $this->em($client)->flush();
            $localTaskId = $this->requiredId($localTask->getId());
            $foreignTaskId = $this->requiredId($foreignTask->getId());
            $client->loginUser($manager);
            $deleteUrl = '/admin/gaming/guild/'.$guildId.'/onboarding/task/'.$localTaskId.'/delete';
            $crawler = $client->request('GET', '/admin/gaming/guild/'.$guildId.'/onboarding');
            self::assertResponseIsSuccessful();
            $deleteToken = $this->actionToken($crawler, $deleteUrl);

            $foreignCompleteUrl = '/admin/gaming/guild/'.$guildId.'/onboarding/task/'.$foreignTaskId.'/complete';
            $client->request('POST', $foreignCompleteUrl);
            self::assertResponseStatusCodeSame(404);

            $foreignDeleteUrl = '/admin/gaming/guild/'.$guildId.'/onboarding/task/'.$foreignTaskId.'/delete';
            $client->request('POST', $foreignDeleteUrl);
            self::assertResponseStatusCodeSame(404);
            self::assertSame(1, $this->em($client)->getRepository(GuildOnboardingTask::class)->count(['guild' => $otherGuild]));

            $client->request('POST', $deleteUrl);
            self::assertResponseStatusCodeSame(403);
            $client->request('POST', $deleteUrl, ['_token' => 'invalid-token']);
            self::assertResponseStatusCodeSame(403);
            self::assertSame(1, $this->em($client)->getRepository(GuildOnboardingTask::class)->count(['guild' => $guild]));

            $client->request('POST', $deleteUrl, [
                '_token' => $deleteToken,
            ]);
            self::assertResponseRedirects('/admin/gaming/guild/'.$guildId.'/onboarding');
            $client->followRedirect();
            self::assertSame(0, $this->em($client)->getRepository(GuildOnboardingTask::class)->count(['guild' => $guild]));
            self::assertSame(1, $this->em($client)->getRepository(GuildOnboardingTask::class)->count(['guild' => $otherGuild]));
        } finally {
            $this->cleanup($client, [$guildId, $otherGuildId], $gameId, $userIds);
        }
    }

    public function testChecklistRequiresGamingPermission(): void
    {
        $client = static::createClient();
        [$game, $guild] = $this->guilds($client);
        $guildId = $this->requiredId($guild->getId());
        $gameId = $this->requiredId($game->getId());
        $userIds = [];

        try {
            $reader = $this->user($client, 'Content editor', [CmsPermission::CONTENT]);
            $userIds[] = $this->requiredId($reader->getId());
            $client->loginUser($reader);
            $client->request('GET', '/admin/gaming/guild/'.$guildId.'/onboarding');
            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->cleanup($client, [$guildId], $gameId, $userIds);
        }
    }

    public function testAllChecklistRoutesHideWhenGamingIsDisabled(): void
    {
        $client = static::createClient();
        $this->removeGamingModuleState($client);
        [$game, $guild] = $this->guilds($client);
        $guildId = $this->requiredId($guild->getId());
        $gameId = $this->requiredId($game->getId());
        $userIds = [];

        try {
            $member = (new GuildMember())->setGuild($guild)->setCharacterName('Checklist member');
            $task = new GuildOnboardingTask($guild, $member, 'Initial meeting');
            $this->em($client)->persist($member);
            $this->em($client)->persist($task);
            $this->em($client)->flush();
            $taskId = $this->requiredId($task->getId());

            $manager = $this->user($client, 'Gaming editor', [CmsPermission::GAMING]);
            $userIds[] = $this->requiredId($manager->getId());
            $client->loginUser($manager);
            $client->request('GET', '/admin/gaming/guild/'.$guildId.'/onboarding');
            self::assertResponseIsSuccessful();

            $disabled = (new CmsModuleState())
                ->setModuleKey('gaming')
                ->updateVersion('1.0.0')
                ->setEnabled(false);
            $this->em($client)->persist($disabled);
            $this->em($client)->flush();

            $client->request('GET', '/admin/gaming/guild/'.$guildId.'/onboarding');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/admin/gaming/guild/'.$guildId.'/onboarding/new');
            self::assertResponseStatusCodeSame(404);
            $client->request('POST', '/admin/gaming/guild/'.$guildId.'/onboarding/task/'.$taskId.'/complete');
            self::assertResponseStatusCodeSame(404);
            $client->request('POST', '/admin/gaming/guild/'.$guildId.'/onboarding/task/'.$taskId.'/delete');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->removeGamingModuleState($client);
            $this->cleanup($client, [$guildId], $gameId, $userIds);
        }
    }

    /** @return array{Game, Guild, Guild} */
    private function guilds(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(4));
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
        $em = $this->em($client);
        $em->persist($game);
        $em->persist($guild);
        $em->persist($otherGuild);
        $em->flush();

        return [$game, $guild, $otherGuild];
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, string $displayName, array $permissions): User
    {
        $user = (new User())
            ->setEmail('guild-onboarding-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName($displayName)
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function formToken(Crawler $crawler, string $name): string
    {
        $token = $crawler->filter('input[name="'.$name.'"]')->attr('value');
        if ($token === null || $token === '') {
            throw new \LogicException('The onboarding form did not render its CSRF token.');
        }

        return $token;
    }

    private function actionToken(Crawler $crawler, string $url): string
    {
        $token = $crawler->filter('form[action="'.$url.'"] input[name="_token"]')->attr('value');
        if ($token === null || $token === '') {
            throw new \LogicException('The onboarding action did not render its CSRF token.');
        }

        return $token;
    }

    /** @return list<array<string, mixed>> */
    private function taskRows(KernelBrowser $client, Guild $guild): array
    {
        return $client->getContainer()->get(GuildOnboardingTaskReadRepository::class)->forGuild($guild);
    }

    /**
     * @param list<int> $guildIds
     * @param list<int> $userIds
     */
    private function cleanup(KernelBrowser $client, array $guildIds, int $gameId, array $userIds): void
    {
        $em = $this->em($client);
        foreach ($guildIds as $guildId) {
            $guild = $em->find(Guild::class, $guildId);
            if (!$guild instanceof Guild) {
                continue;
            }
            foreach ($em->getRepository(GuildOnboardingTask::class)->findBy(['guild' => $guild]) as $task) {
                $em->remove($task);
            }
            foreach ($em->getRepository(GuildMember::class)->findBy(['guild' => $guild]) as $member) {
                $em->remove($member);
            }
            $em->flush();
            $em->remove($guild);
            $em->flush();
        }

        $game = $em->find(Game::class, $gameId);
        if ($game instanceof Game) {
            $em->remove($game);
        }
        foreach ($userIds as $userId) {
            $user = $em->find(User::class, $userId);
            if ($user instanceof User) {
                $em->remove($user);
            }
        }
        $em->flush();
    }

    private function removeGamingModuleState(KernelBrowser $client): void
    {
        $em = $this->em($client);
        $state = $em->find(CmsModuleState::class, 'gaming');
        if ($state instanceof CmsModuleState) {
            $em->remove($state);
            $em->flush();
        }
        $em->clear();
    }

    private function requiredId(?int $id): int
    {
        if ($id === null) {
            throw new \LogicException('Expected a persisted fixture ID.');
        }

        return $id;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
