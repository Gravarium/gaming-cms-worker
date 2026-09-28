<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminNotification;
use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\Guild\GuildRoleNeed;
use App\Entity\GuildApplication;
use App\Entity\User;
use App\Security\CmsPermission;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class PublicGuildRoleNeedApplicationFlowTest extends WebTestCase
{
    private const WIDGET_KEY = 'gaming.public-guild-role-needs';
    private const MODULE_KEYS = ['content', 'gaming'];

    public function testWidgetLinkPrefillsRoleAndSuccessfulApplicationShowsTheRoleToOfficers(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->moduleSnapshot($client);
        $this->enableGaming($client);
        $em = $this->em($client);

        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())->setName('Role application game '.$suffix)->setSlug('role-application-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Role application guild '.$suffix)
            ->setSlug('role-application-guild-'.$suffix)
            ->setServerName('EU')
            ->setDescription('Public role application flow test')
            ->setRecruitmentOpen(true);
        $need = (new GuildRoleNeed($guild, $game, 'Tank', 'Healer'))->setDesiredCount(2);
        $officer = (new User())
            ->setEmail('role-application-officer-'.$suffix.'@example.test')
            ->setDisplayName('Role application officer '.$suffix)
            ->setPermissions([CmsPermission::GAMING])
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $email = 'role-application-applicant-'.$suffix.'@example.test';
        $notificationTitle = 'Neue Bewerbung für '.$guild->getName();

        foreach ([$game, $guild, $need, $officer] as $fixture) {
            $em->persist($fixture);
        }
        $em->flush();
        $gameId = $game->getId();
        $guildId = $guild->getId();
        $officerId = $officer->getId();
        $guildSlug = $guild->getSlug();

        try {
            $registry = $client->getContainer()->get(WidgetRegistry::class);
            $definition = $registry->get(self::WIDGET_KEY);
            self::assertNotNull($definition);
            $data = $registry->data(self::WIDGET_KEY, ['count' => 6]);
            $widgetHtml = $client->getContainer()->get(Environment::class)->render($definition->template, $data);
            $widget = new Crawler($widgetHtml);
            $applyLink = $widget->filter('a[href*="/gaming/guild/'.$guildSlug.'/apply/role-need"]');
            self::assertCount(1, $applyLink);
            self::assertSelectorTextContains('body', ''); // Keep assertions scoped to the rendered widget below.
            self::assertStringContainsString('Für diese Rolle bewerben', $widgetHtml);

            $applyUrl = (string) $applyLink->attr('href');
            $crawler = $client->request('GET', $applyUrl);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', $guild->getName());
            self::assertSelectorTextContains('body', 'Tank · Healer');
            self::assertSame('Healer', $crawler->filter('input[name="guild_application[characterClass]"]')->attr('value'));
            $this->assertPrivateHeaders($client);

            $form = $crawler->selectButton('Bewerbung absenden')->form();
            self::assertSame('Healer', (string) $form['guild_application[characterClass]']->getValue());
            $form['guild_application[applicantName]'] = 'Applicant '.$suffix;
            $form['guild_application[email]'] = $email;
            $form['guild_application[characterName]'] = 'Character '.$suffix;
            $form['guild_application[message]'] = 'This is a sufficiently long application message.';
            $client->submit($form);

            self::assertResponseRedirects('/gaming/guild/'.$guildSlug);
            $this->assertPrivateHeaders($client);

            $em->clear();
            $application = $em->getRepository(GuildApplication::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(GuildApplication::class, $application);
            self::assertSame('Healer', $application->getCharacterClass());
            self::assertSame([[
                'question' => 'Gesuchte Rolle',
                'answer' => 'Tank · Healer',
            ]], $application->getAnswers());
            $applicationId = $application->getId();
            self::assertNotNull($applicationId);
            self::assertInstanceOf(AdminNotification::class, $em->getRepository(AdminNotification::class)->findOneBy([
                'type' => 'guild_application',
                'title' => $notificationTitle,
            ]));

            $client->loginUser($officer);
            $client->request('GET', '/admin/gaming/applications/'.$applicationId.'/review');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Gesuchte Rolle');
            self::assertSelectorTextContains('body', 'Tank · Healer');
        } finally {
            $this->cleanup($client, $email, $notificationTitle, $guildId, $gameId, $officerId);
            $this->restoreModuleSnapshot($client, $moduleSnapshot);
        }
    }

    public function testFocusedRouteRejectsForeignAndNoLongerPublicNeeds(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->moduleSnapshot($client);
        $this->enableGaming($client);
        $em = $this->em($client);

        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())->setName('Role route game '.$suffix)->setSlug('role-route-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Role route guild '.$suffix)
            ->setSlug('role-route-guild-'.$suffix)
            ->setServerName('EU')
            ->setDescription('Role route access test')
            ->setRecruitmentOpen(true);
        $foreignGuild = (new Guild())
            ->setGame($game)
            ->setName('Foreign role route guild '.$suffix)
            ->setSlug('foreign-role-route-guild-'.$suffix)
            ->setServerName('EU')
            ->setDescription('Foreign role route access test')
            ->setRecruitmentOpen(true);
        $need = (new GuildRoleNeed($guild, $game, 'Healer', 'Priest'))->setDesiredCount(1);
        $foreignNeed = (new GuildRoleNeed($foreignGuild, $game, 'Tank', 'Warrior'))->setDesiredCount(1);

        foreach ([$game, $guild, $foreignGuild, $need, $foreignNeed] as $fixture) {
            $em->persist($fixture);
        }
        $em->flush();
        $guildId = $guild->getId();
        $gameId = $game->getId();
        $guildSlug = $guild->getSlug();
        $foreignGuildSlug = $foreignGuild->getSlug();

        try {
            $client->request('GET', $this->applyUrl($guildSlug, $game->getSlug(), $foreignNeed->getRoleKey(), $foreignNeed->getClassKey()));
            self::assertResponseStatusCodeSame(404);

            $need->setActive(false);
            $em->flush();
            $client->request('GET', $this->applyUrl($guildSlug, $game->getSlug(), $need->getRoleKey(), $need->getClassKey()));
            self::assertResponseStatusCodeSame(404);

            $need->setActive(true);
            $em->flush();
            $this->setModuleState($client, 'gaming', false);
            $em->clear();
            $client->request('GET', $this->applyUrl($guildSlug, $game->getSlug(), 'Healer', 'Priest'));
            self::assertResponseStatusCodeSame(404);

            self::assertSame(0, $em->getRepository(GuildApplication::class)->count([
                'guild' => $guildId,
            ]));
        } finally {
            $this->cleanup($client, '', '', $guildId, $gameId, null, $foreignGuildSlug);
            $this->restoreModuleSnapshot($client, $moduleSnapshot);
        }
    }

    private function applyUrl(string $guildSlug, string $gameSlug, string $role, string $classKey): string
    {
        return '/gaming/guild/'.$guildSlug.'/apply/role-need?'.http_build_query([
            'game' => $gameSlug,
            'role' => $role,
            'classKey' => $classKey,
        ]);
    }

    /** @return array{content: bool|null, gaming: bool|null} */
    private function moduleSnapshot(KernelBrowser $client): array
    {
        $em = $this->em($client);
        $snapshot = ['content' => null, 'gaming' => null];
        foreach (self::MODULE_KEYS as $key) {
            $snapshot[$key] = $em->find(CmsModuleState::class, $key)?->isEnabled();
        }

        return $snapshot;
    }

    private function enableGaming(KernelBrowser $client): void
    {
        $this->setModuleState($client, 'content', true);
        $this->setModuleState($client, 'gaming', true);
    }

    private function setModuleState(KernelBrowser $client, string $key, bool $enabled): void
    {
        $em = $this->em($client);
        $state = $em->find(CmsModuleState::class, $key);
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey($key)->updateVersion('1.0.0');
            $em->persist($state);
        }
        $state->setEnabled($enabled);
        $em->flush();
    }

    /** @param array{content: bool|null, gaming: bool|null} $snapshot */
    private function restoreModuleSnapshot(KernelBrowser $client, array $snapshot): void
    {
        $em = $this->em($client);
        $em->clear();
        foreach ($snapshot as $key => $previous) {
            $state = $em->find(CmsModuleState::class, $key);
            if ($previous === null) {
                if ($state instanceof CmsModuleState) {
                    $em->remove($state);
                }
            } elseif ($state instanceof CmsModuleState) {
                $state->setEnabled($previous);
            } else {
                $em->persist((new CmsModuleState())->setModuleKey($key)->updateVersion('1.0.0')->setEnabled($previous));
            }
        }
        $em->flush();
        $em->clear();
    }

    private function cleanup(
        KernelBrowser $client,
        string $email,
        string $notificationTitle,
        ?int $guildId,
        ?int $gameId,
        ?int $officerId,
        ?string $foreignGuildSlug = null,
    ): void {
        $em = $this->em($client);
        $em->clear();

        if ($email !== '') {
            $application = $em->getRepository(GuildApplication::class)->findOneBy(['email' => $email]);
            if ($application instanceof GuildApplication) {
                $em->remove($application);
            }
        }
        if ($notificationTitle !== '') {
            $notification = $em->getRepository(AdminNotification::class)->findOneBy(['title' => $notificationTitle]);
            if ($notification instanceof AdminNotification) {
                $em->remove($notification);
            }
        }
        if ($em->contains($em->getRepository(GuildRoleNeed::class)->findOneBy([
            'guild' => $guildId,
            'game' => $gameId,
            'roleKey' => $foreignGuildSlug === null ? 'Tank' : 'Healer',
            'classKey' => $foreignGuildSlug === null ? 'Healer' : 'Priest',
        ]))) {
            $em->remove($em->getRepository(GuildRoleNeed::class)->findOneBy([
                'guild' => $guildId,
                'game' => $gameId,
                'roleKey' => $foreignGuildSlug === null ? 'Tank' : 'Healer',
                'classKey' => $foreignGuildSlug === null ? 'Healer' : 'Priest',
            ]));
        }
        if ($foreignGuildSlug !== null && $gameId !== null) {
            $foreignGuild = $em->getRepository(Guild::class)->findOneBy(['slug' => $foreignGuildSlug]);
            if ($foreignGuild instanceof Guild) {
                $foreignNeed = $em->getRepository(GuildRoleNeed::class)->findOneBy([
                    'guild' => $foreignGuild,
                    'game' => $gameId,
                    'roleKey' => 'Tank',
                    'classKey' => 'Warrior',
                ]);
                if ($foreignNeed instanceof GuildRoleNeed) {
                    $em->remove($foreignNeed);
                }
                $em->remove($foreignGuild);
            }
        }
        if ($guildId !== null) {
            $guild = $em->find(Guild::class, $guildId);
            if ($guild instanceof Guild) {
                $em->remove($guild);
            }
        }
        if ($gameId !== null) {
            $game = $em->find(Game::class, $gameId);
            if ($game instanceof Game) {
                $em->remove($game);
            }
        }
        if ($officerId !== null) {
            $officer = $em->find(User::class, $officerId);
            if ($officer instanceof User) {
                $em->remove($officer);
            }
        }
        $em->flush();
        $em->clear();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
