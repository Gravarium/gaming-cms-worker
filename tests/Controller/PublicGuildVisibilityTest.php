<?php

declare(strict_types=1);

namespace App\\Tests\\Controller;

use App\\Entity\\Game;
use App\\Entity\\Guild;
use Doctrine\\ORM\\EntityManagerInterface;
use Symfony\\Bundle\\FrameworkBundle\\KernelBrowser;
use Symfony\\Bundle\\FrameworkBundle\\Test\\WebTestCase;

final class PublicGuildVisibilityTest extends WebTestCase
{
    public function testPublicDirectoryListsOnlyEnabledGuildsOnEnabledGames(): void
    {
        $client = static::createClient();
        $fixture = $this->guilds($client);

        $client->request('GET', '/gaming');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($fixture['visibleName'], $content);
        self::assertStringNotContainsString($fixture['disabledGuildName'], $content);
        self::assertStringNotContainsString($fixture['disabledGameGuildName'], $content);
    }

    public function testEnabledGuildDetailsRemainPublicAndHiddenGuildRoutesReturnNotFound(): void
    {
        $client = static::createClient();
        $fixture = $this->guilds($client);

        $client->request('GET', '/gaming/guild/'.$fixture['visibleSlug']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $fixture['visibleName']);

        foreach ([$fixture['disabledGuildSlug'], $fixture['disabledGameGuildSlug']] as $slug) {
            foreach (['', '/apply'] as $suffix) {
                $path = '/gaming/guild/'.$slug.$suffix;
                $client->request('GET', $path);
                self::assertResponseStatusCodeSame(404, $path);
            }
        }
    }

    /**
     * @return array{
     *   visibleName: string,
     *   visibleSlug: string,
     *   disabledGuildName: string,
     *   disabledGuildSlug: string,
     *   disabledGameGuildName: string,
     *   disabledGameGuildSlug: string
     * }
     */
    private function guilds(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(5));
        $enabledGame = (new Game())
            ->setName('Visible Guild Game '.$suffix)
            ->setSlug('visible-guild-game-'.$suffix)
            ->setEnabled(true);
        $disabledGame = (new Game())
            ->setName('Disabled Guild Game '.$suffix)
            ->setSlug('disabled-guild-game-'.$suffix)
            ->setEnabled(false);

        $visibleName = 'Public Guild '.$suffix;
        $visibleSlug = 'public-guild-'.$suffix;
        $visibleGuild = (new Guild())
            ->setGame($enabledGame)
            ->setName($visibleName)
            ->setSlug($visibleSlug)
            ->setServerName('Public server')
            ->setDescription('Enabled public guild')
            ->setEnabled(true);

        $disabledGuildName = 'Disabled Guild '.$suffix;
        $disabledGuildSlug = 'disabled-guild-'.$suffix;
        $disabledGuild = (new Guild())
            ->setGame($enabledGame)
            ->setName($disabledGuildName)
            ->setSlug($disabledGuildSlug)
            ->setServerName('Disabled guild server')
            ->setDescription('Disabled guild')
            ->setEnabled(false);

        $disabledGameGuildName = 'Guild on Disabled Game '.$suffix;
        $disabledGameGuildSlug = 'disabled-game-guild-'.$suffix;
        $disabledGameGuild = (new Guild())
            ->setGame($disabledGame)
            ->setName($disabledGameGuildName)
            ->setSlug($disabledGameGuildSlug)
            ->setServerName('Disabled game server')
            ->setDescription('Guild on a disabled game')
            ->setEnabled(true);

        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        foreach ([$enabledGame, $disabledGame, $visibleGuild, $disabledGuild, $disabledGameGuild] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return [
            'visibleName' => $visibleName,
            'visibleSlug' => $visibleSlug,
            'disabledGuildName' => $disabledGuildName,
            'disabledGuildSlug' => $disabledGuildSlug,
            'disabledGameGuildName' => $disabledGameGuildName,
            'disabledGameGuildSlug' => $disabledGameGuildSlug,
        ];
    }
}
