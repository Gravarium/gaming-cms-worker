<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameHubLink;
use App\Entity\Guild;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminGameHubGuildLinkWorkflowTest extends WebTestCase
{
    public function testManagerCanLinkAndRemoveSameGameGuildsFromPublicGameHub(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $gameSlug = 'linked-game-'.$suffix;
        $otherGameSlug = 'other-linked-game-'.$suffix;
        $guildSlug = 'linked-guild-'.$suffix;
        $otherGuildSlug = 'other-game-guild-'.$suffix;
        $disabledGuildSlug = 'disabled-guild-'.$suffix;
        $userEmail = 'game-hub-link-manager-'.$suffix.'@example.test';

        $game = $this->game('Linked game '.$suffix, $gameSlug);
        $otherGame = $this->game('Other linked game '.$suffix, $otherGameSlug);
        $entry = new GameCatalogueEntry($game);
        $otherEntry = new GameCatalogueEntry($otherGame);
        $guild = $this->guild($game, 'Linked guild '.$suffix, $guildSlug);
        $otherGameGuild = $this->guild($otherGame, 'Other game guild '.$suffix, $otherGuildSlug);
        $disabledGuild = $this->guild($game, 'Disabled guild '.$suffix, $disabledGuildSlug, false);
        $manager = $this->user($userEmail, [CmsPermission::GAMING]);
        foreach ([$game, $otherGame, $entry, $otherEntry, $guild, $otherGameGuild, $disabledGuild, $manager] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $entryId = $entry->getId();
        $otherEntryId = $otherEntry->getId();
        $guildId = $guild->getId();
        $otherGuildId = $otherGameGuild->getId();
        $disabledGuildId = $disabledGuild->getId();
        self::assertNotNull($entryId);
        self::assertNotNull($otherEntryId);
        self::assertNotNull($guildId);
        self::assertNotNull($otherGuildId);
        self::assertNotNull($disabledGuildId);
        $managerPath = '/admin/gaming/game-hubs/'.$entryId.'/guild-links';

        try {
            $client->loginUser($manager);
            $crawler = $client->request('GET', '/games/'.$gameSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="'.$managerPath.'"]');

            $crawler = $client->click($crawler->selectLink('Gilden verwalten')->link());
            self::assertResponseIsSuccessful();
            $formName = (string) $crawler->filter('form')->first()->attr('name');
            $token = (string) $crawler->filter('form input[name$="[_token]"]')->attr('value');
            self::assertSame(1, $crawler->filter('select[name="'.$formName.'[guild]"] option[value="'.$guildId.'"]')->count());
            self::assertSame(0, $crawler->filter('select[name="'.$formName.'[guild]"] option[value="'.$otherGuildId.'"]')->count());
            self::assertSame(0, $crawler->filter('select[name="'.$formName.'[guild]"] option[value="'.$disabledGuildId.'"]')->count());

            $client->request('POST', $managerPath, [
                $formName => ['_token' => $token, 'guild' => (string) $otherGuildId],
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'Diese Gilde ist für diesen Game Hub nicht verfügbar.');

            $crawler = $client->request('GET', $managerPath);
            $formName = (string) $crawler->filter('form')->first()->attr('name');
            $token = (string) $crawler->filter('form input[name$="[_token]"]')->attr('value');
            $client->request('POST', $managerPath, [
                $formName => ['_token' => $token, 'guild' => (string) $guildId],
            ]);
            self::assertResponseRedirects($managerPath);

            $crawler = $client->request('GET', $managerPath);
            $formName = (string) $crawler->filter('form')->first()->attr('name');
            $token = (string) $crawler->filter('form input[name$="[_token]"]')->attr('value');
            $client->request('POST', $managerPath, [
                $formName => ['_token' => $token, 'guild' => (string) $guildId],
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'Diese Gilde ist bereits mit dem Game Hub verknüpft.');

            $crossEntryDeletePath = '/admin/gaming/game-hubs/'.$otherEntryId.'/guild-links/'.$guildId.'/delete';
            $client->request('POST', $crossEntryDeletePath);
            self::assertResponseStatusCodeSame(404);

            $crawler = $client->request('GET', '/games/'.$gameSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/gaming/guild/'.$guildSlug.'"]');
            self::assertSelectorTextContains('a[href="/gaming/guild/'.$guildSlug.'"]', 'Linked guild '.$suffix);
            self::assertSelectorNotExists('a[href="/gaming/guild/'.$otherGuildSlug.'"]');

            $crawler = $client->request('GET', $managerPath);
            $deletePath = $managerPath.'/'.$guildId.'/delete';
            $deleteForm = $crawler->filter('form[action="'.$deletePath.'"]')->form();
            $deleteForm['_token'] = 'invalid-token';
            $client->submit($deleteForm);
            self::assertResponseStatusCodeSame(403);

            $crawler = $client->request('GET', $managerPath);
            $deleteForm = $crawler->filter('form[action="'.$deletePath.'"]')->form();
            $client->submit($deleteForm);
            self::assertResponseRedirects($managerPath);

            $client->request('GET', '/games/'.$gameSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('a[href="/gaming/guild/'.$guildSlug.'"]');
        } finally {
            $this->restoreFixtures($client, [$gameSlug, $otherGameSlug], [$guildSlug, $otherGuildSlug, $disabledGuildSlug], [$userEmail]);
        }
    }

    public function testPublicGameHubHidesMissingDisabledAndCrossGameGuildTargets(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $gameSlug = 'public-links-game-'.$suffix;
        $otherGameSlug = 'private-links-game-'.$suffix;
        $disabledGameSlug = 'disabled-links-game-'.$suffix;
        $guildSlug = 'public-linked-guild-'.$suffix;
        $disabledGuildSlug = 'hidden-linked-guild-'.$suffix;
        $otherGuildSlug = 'cross-game-linked-guild-'.$suffix;
        $disabledGameGuildSlug = 'disabled-game-linked-guild-'.$suffix;

        $game = $this->game('Public links game '.$suffix, $gameSlug);
        $otherGame = $this->game('Private links game '.$suffix, $otherGameSlug);
        $disabledGame = $this->game('Disabled links game '.$suffix, $disabledGameSlug, false);
        $entry = new GameCatalogueEntry($game);
        $guild = $this->guild($game, 'Public linked guild '.$suffix, $guildSlug);
        $disabledGuild = $this->guild($game, 'Hidden linked guild '.$suffix, $disabledGuildSlug, false);
        $otherGuild = $this->guild($otherGame, 'Cross-game guild '.$suffix, $otherGuildSlug);
        $disabledGameGuild = $this->guild($disabledGame, 'Disabled-game guild '.$suffix, $disabledGameGuildSlug);
        $entityManager->persist($game);
        $entityManager->persist($otherGame);
        $entityManager->persist($disabledGame);
        $entityManager->persist($entry);
        $entityManager->persist($guild);
        $entityManager->persist($disabledGuild);
        $entityManager->persist($otherGuild);
        $entityManager->persist($disabledGameGuild);
        $entityManager->flush();

        foreach ([
            new GameHubLink($entry, 'guild', (int) $guild->getId(), 'Current guild label'),
            new GameHubLink($entry, 'guild', (int) $disabledGuild->getId(), 'Hidden guild label'),
            new GameHubLink($entry, 'guild', (int) $otherGuild->getId(), 'Cross-game guild label'),
            new GameHubLink($entry, 'guild', (int) $disabledGameGuild->getId(), 'Disabled-game guild label'),
            new GameHubLink($entry, 'guild', 2147483647, 'Missing guild label'),
            new GameHubLink($entry, 'video', 4, 'Linked video metadata'),
        ] as $link) {
            $entityManager->persist($link);
        }
        $entityManager->flush();

        try {
            $client->request('GET', '/games/'.$gameSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/gaming/guild/'.$guildSlug.'"]');
            $response = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Public linked guild '.$suffix, $response);
            self::assertStringNotContainsString('Hidden guild label', $response);
            self::assertStringNotContainsString('Hidden linked guild '.$suffix, $response);
            self::assertStringNotContainsString('Cross-game guild label', $response);
            self::assertStringNotContainsString('Cross-game guild '.$suffix, $response);
            self::assertStringNotContainsString('Disabled-game guild label', $response);
            self::assertStringNotContainsString('Disabled-game guild '.$suffix, $response);
            self::assertStringNotContainsString('Missing guild label', $response);
            self::assertStringContainsString('Video: Linked video metadata', $response);
        } finally {
            $this->restoreFixtures($client, [$gameSlug, $otherGameSlug, $disabledGameSlug], [$guildSlug, $disabledGuildSlug, $otherGuildSlug, $disabledGameGuildSlug], []);
        }
    }

    public function testManagerLoginCsrfAndDisabledGamingModuleProtectTheWorkflow(): void
    {
        $managerClient = static::createClient();
        $entityManager = $managerClient->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $gameSlug = 'permissions-game-'.$suffix;
        $guildSlug = 'permissions-guild-'.$suffix;
        $managerEmail = 'authorized-game-hub-'.$suffix.'@example.test';

        $game = $this->game('Permissions game '.$suffix, $gameSlug);
        $entry = new GameCatalogueEntry($game);
        $guild = $this->guild($game, 'Permissions guild '.$suffix, $guildSlug);
        $manager = $this->user($managerEmail, [CmsPermission::GAMING]);
        foreach ([$game, $entry, $guild, $manager] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $entryId = $entry->getId();
        $guildId = $guild->getId();
        self::assertNotNull($entryId);
        self::assertNotNull($guildId);
        $managerPath = '/admin/gaming/game-hubs/'.$entryId.'/guild-links';

        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        $createdState = !($state instanceof CmsModuleState);
        $originalEnabled = $state instanceof CmsModuleState ? $state->isEnabled() : null;
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
            $entityManager->persist($state);
        }
        $state->setEnabled(true);
        $entityManager->flush();

        try {
            $managerClient->request('GET', $managerPath);
            self::assertResponseRedirects('/login');

            $managerClient->loginUser($manager);

            $crawler = $managerClient->request('GET', '/games/'.$gameSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="'.$managerPath.'"]');

            $crawler = $managerClient->request('GET', $managerPath);
            self::assertResponseIsSuccessful();
            $formName = (string) $crawler->filter('form')->first()->attr('name');
            $managerClient->request('POST', $managerPath, [
                $formName => ['_token' => 'invalid-token', 'guild' => (string) $guildId],
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(
                0,
                $managerClient->getContainer()->get(EntityManagerInterface::class)->getRepository(GameHubLink::class)->count([
                    'entry' => $entry,
                    'targetType' => 'guild',
                    'targetId' => $guildId,
                ]),
            );

            $stateManager = $managerClient->getContainer()->get(EntityManagerInterface::class);
            $disabledState = $stateManager->find(CmsModuleState::class, 'gaming');
            self::assertInstanceOf(CmsModuleState::class, $disabledState);
            $disabledState->setEnabled(false);
            $stateManager->flush();

            $managerClient->request('GET', $managerPath);
            self::assertResponseStatusCodeSame(404);
            $managerClient->request('GET', '/games/'.$gameSlug);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $cleanupManager = $managerClient->getContainer()->get(EntityManagerInterface::class);
            $cleanupState = $cleanupManager->find(CmsModuleState::class, 'gaming');
            if ($createdState) {
                if ($cleanupState instanceof CmsModuleState) {
                    $cleanupManager->remove($cleanupState);
                }
            } elseif ($cleanupState instanceof CmsModuleState) {
                $cleanupState->setEnabled((bool) $originalEnabled);
            }
            $cleanupManager->flush();
            $this->restoreFixtures($managerClient, [$gameSlug], [$guildSlug], [$managerEmail]);
        }
    }

    public function testViewerCannotAccessGameHubGuildLinkWorkflow(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $gameSlug = 'viewer-game-hub-'.$suffix;
        $viewerEmail = 'viewer-game-hub-'.$suffix.'@example.test';

        $game = $this->game('Viewer game '.$suffix, $gameSlug);
        $entry = new GameCatalogueEntry($game);
        $viewer = $this->user($viewerEmail, [CmsPermission::ACCESS]);
        foreach ([$game, $entry, $viewer] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        $entryId = $entry->getId();
        self::assertNotNull($entryId);
        $managerPath = '/admin/gaming/game-hubs/'.$entryId.'/guild-links';

        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        $createdState = !($state instanceof CmsModuleState);
        $originalEnabled = $state instanceof CmsModuleState ? $state->isEnabled() : null;
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
            $entityManager->persist($state);
        }
        $state->setEnabled(true);
        $entityManager->flush();

        try {
            $client->loginUser($viewer);
            $client->request('GET', $managerPath);
            self::assertResponseStatusCodeSame(403);

            $client->request('GET', '/games/'.$gameSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('a[href="'.$managerPath.'"]');
        } finally {
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            $cleanupState = $cleanupManager->find(CmsModuleState::class, 'gaming');
            if ($createdState) {
                if ($cleanupState instanceof CmsModuleState) {
                    $cleanupManager->remove($cleanupState);
                }
            } elseif ($cleanupState instanceof CmsModuleState) {
                $cleanupState->setEnabled((bool) $originalEnabled);
            }
            $cleanupManager->flush();
            $this->restoreFixtures($client, [$gameSlug], [], [$viewerEmail]);
        }
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions): User
    {
        return (new User())
            ->setEmail($email)
            ->setDisplayName('Game Hub test user')
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions)
            ->verifyEmail();
    }

    private function game(string $name, string $slug, bool $enabled = true): Game
    {
        return (new Game())->setName($name)->setSlug($slug)->setEnabled($enabled);
    }

    private function guild(Game $game, string $name, string $slug, bool $enabled = true): Guild
    {
        return (new Guild())
            ->setGame($game)
            ->setName($name)
            ->setSlug($slug)
            ->setServerName('Test server')
            ->setDescription('Test guild description.')
            ->setEnabled($enabled);
    }

    /** @param list<string> $gameSlugs
     * @param list<string> $guildSlugs
     * @param list<string> $userEmails
     */
    private function restoreFixtures(KernelBrowser $client, array $gameSlugs, array $guildSlugs, array $userEmails): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $games = [];
        foreach ($gameSlugs as $slug) {
            $game = $entityManager->getRepository(Game::class)->findOneBy(['slug' => $slug]);
            if ($game instanceof Game) {
                $games[$slug] = $game;
                foreach ($entityManager->getRepository(GameCatalogueEntry::class)->findBy(['game' => $game]) as $entry) {
                    foreach ($entityManager->getRepository(GameHubLink::class)->findBy(['entry' => $entry]) as $link) {
                        $entityManager->remove($link);
                    }
                    $entityManager->remove($entry);
                }
            }
        }
        foreach ($guildSlugs as $slug) {
            $guild = $entityManager->getRepository(Guild::class)->findOneBy(['slug' => $slug]);
            if ($guild instanceof Guild) {
                $entityManager->remove($guild);
            }
        }
        foreach ($games as $game) {
            $entityManager->remove($game);
        }
        foreach ($userEmails as $email) {
            $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user instanceof User) {
                $entityManager->remove($user);
            }
        }
        $entityManager->flush();
    }
}
