<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GamingAccessTest extends WebTestCase
{
    public function testGamingAdministrationRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/gaming');
        self::assertResponseRedirects('/login');
    }

    public function testPublicGamingOverviewIsAvailable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/gaming');
        self::assertResponseIsSuccessful();
    }

    public function testPublicGamingOverviewFiltersByGameAndCanReturnToAllGames(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(6));

        $selectedGame = $this->game($client, $suffix, 'selected');
        $otherGame = $this->game($client, $suffix, 'other');
        $disabledGame = $this->game($client, $suffix, 'disabled', false);

        $selectedGuild = $this->guild($client, $selectedGame, $suffix, 'selected');
        $otherGuild = $this->guild($client, $otherGame, $suffix, 'other');
        $disabledGuild = $this->guild($client, $selectedGame, $suffix, 'disabled', false);
        $disabledGameGuild = $this->guild($client, $disabledGame, $suffix, 'disabled-game');

        $this->entityManager($client)->flush();

        $client->request('GET', '/gaming?game='.$selectedGame->getSlug());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav[aria-label="Gilden nach Spiel filtern"] a[aria-current="page"][href="/gaming?game='.$selectedGame->getSlug().'"]');
        self::assertSelectorExists('a[href="/gaming"]');
        self::assertSelectorTextContains('body', $selectedGuild->getName());
        self::assertSelectorTextNotContains('body', $otherGuild->getName());
        self::assertSelectorTextNotContains('body', $disabledGuild->getName());
        self::assertSelectorTextNotContains('body', $disabledGameGuild->getName());
        self::assertSelectorTextNotContains('body', $disabledGame->getName());

        $client->click($client->getCrawler()->selectLink('Alle Spiele')->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $selectedGuild->getName());
        self::assertSelectorTextContains('body', $otherGuild->getName());
        self::assertSelectorTextNotContains('body', $disabledGuild->getName());
        self::assertSelectorTextNotContains('body', $disabledGameGuild->getName());
    }

    public function testPublicGamingFilterFailsClosedForMalformedUnknownAndDisabledGameSlugs(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(6));

        $enabledGame = $this->game($client, $suffix, 'enabled');
        $disabledGame = $this->game($client, $suffix, 'disabled', false);
        $enabledGuild = $this->guild($client, $enabledGame, $suffix, 'visible');
        $disabledGameGuild = $this->guild($client, $disabledGame, $suffix, 'disabled-game');

        $this->entityManager($client)->flush();

        $invalidUris = [
            '/gaming?game%5B%5D='.rawurlencode($enabledGame->getSlug()),
            '/gaming?game='.str_repeat('a', 141),
            '/gaming?game=unknown-'.$suffix,
            '/gaming?game='.$disabledGame->getSlug(),
        ];

        foreach ($invalidUris as $uri) {
            $client->request('GET', $uri);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('p[role="status"]', 'Dieses Spiel ist nicht verfügbar');
            self::assertSelectorTextNotContains('body', $enabledGuild->getName());
            self::assertSelectorTextNotContains('body', $disabledGameGuild->getName());
        }
    }

    private function game(KernelBrowser $client, string $suffix, string $label, bool $enabled = true): Game
    {
        $game = (new Game())
            ->setName('Filter game '.$label.' '.$suffix)
            ->setSlug('gaming-filter-'.$suffix.'-'.$label)
            ->setEnabled($enabled);
        $this->entityManager($client)->persist($game);

        return $game;
    }

    private function guild(KernelBrowser $client, Game $game, string $suffix, string $label, bool $enabled = true): Guild
    {
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Filter guild '.$label.' '.$suffix)
            ->setSlug('gaming-filter-guild-'.$suffix.'-'.$label)
            ->setServerName('Test server')
            ->setDescription('Public gaming filter test guild')
            ->setEnabled($enabled);
        $this->entityManager($client)->persist($guild);

        return $guild;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
