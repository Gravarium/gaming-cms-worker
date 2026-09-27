<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicGuildVisibilityBoundaryTest extends WebTestCase
{
    public function testOnlyGuildsOnEnabledGamesArePublic(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $token = bin2hex(random_bytes(6));

        $enabledGameSlug = 'visible-game-'.$token;
        $disabledGameSlug = 'hidden-game-'.$token;
        $visibleGuildSlug = 'visible-guild-'.$token;
        $disabledGuildSlug = 'disabled-guild-'.$token;
        $disabledGameGuildSlug = 'disabled-game-guild-'.$token;

        $enabledGameName = 'Visible game '.$token;
        $disabledGameName = 'Hidden game '.$token;
        $visibleGuildName = 'Visible guild '.$token;
        $disabledGuildName = 'Disabled guild '.$token;
        $disabledGameGuildName = 'Guild on disabled game '.$token;

        $enabledGame = (new Game())->setName($enabledGameName)->setSlug($enabledGameSlug);
        $disabledGame = (new Game())->setName($disabledGameName)->setSlug($disabledGameSlug)->setEnabled(false);
        $visibleGuild = (new Guild())
            ->setGame($enabledGame)
            ->setName($visibleGuildName)
            ->setSlug($visibleGuildSlug)
            ->setServerName('Synthetic server')
            ->setDescription('Visible synthetic guild');
        $disabledGuild = (new Guild())
            ->setGame($enabledGame)
            ->setName($disabledGuildName)
            ->setSlug($disabledGuildSlug)
            ->setServerName('Synthetic server')
            ->setDescription('Hidden synthetic guild')
            ->setEnabled(false)
            ->setRecruitmentOpen(true);
        $disabledGameGuild = (new Guild())
            ->setGame($disabledGame)
            ->setName($disabledGameGuildName)
            ->setSlug($disabledGameGuildSlug)
            ->setServerName('Synthetic server')
            ->setDescription('Guild on a hidden game')
            ->setRecruitmentOpen(true);

        $gameSlugs = [$enabledGameSlug, $disabledGameSlug];
        $guildSlugs = [$visibleGuildSlug, $disabledGuildSlug, $disabledGameGuildSlug];

        try {
            $entityManager->persist($enabledGame);
            $entityManager->persist($disabledGame);
            $entityManager->persist($visibleGuild);
            $entityManager->persist($disabledGuild);
            $entityManager->persist($disabledGameGuild);
            $entityManager->flush();

            $client->request('GET', '/gaming');
            self::assertResponseIsSuccessful();
            $indexBody = (string) $client->getResponse()->getContent();
            self::assertStringContainsString($visibleGuildName, $indexBody);
            self::assertStringNotContainsString($disabledGuildName, $indexBody);
            self::assertStringNotContainsString($disabledGameGuildName, $indexBody);
            self::assertStringNotContainsString($disabledGameName, $indexBody);

            $client->request('GET', '/gaming/guild/'.$visibleGuildSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', $visibleGuildName);

            $client->request('GET', '/gaming/guild/'.$disabledGuildSlug);
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString($disabledGuildName, (string) $client->getResponse()->getContent());

            $client->request('GET', '/gaming/guild/'.$disabledGuildSlug.'/apply');
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString($disabledGuildName, (string) $client->getResponse()->getContent());

            $client->request('GET', '/gaming/guild/'.$disabledGameGuildSlug);
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString($disabledGameGuildName, (string) $client->getResponse()->getContent());

            $client->request('GET', '/gaming/guild/'.$disabledGameGuildSlug.'/apply');
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString($disabledGameGuildName, (string) $client->getResponse()->getContent());
        } finally {
            $cleanupEntityManager = $client->getContainer()->get(EntityManagerInterface::class);
            $cleanupEntityManager->clear();

            foreach ($guildSlugs as $slug) {
                $storedGuild = $cleanupEntityManager->getRepository(Guild::class)->findOneBy(['slug' => $slug]);
                if ($storedGuild instanceof Guild) {
                    $cleanupEntityManager->remove($storedGuild);
                }
            }

            foreach ($gameSlugs as $slug) {
                $storedGame = $cleanupEntityManager->getRepository(Game::class)->findOneBy(['slug' => $slug]);
                if ($storedGame instanceof Game) {
                    $cleanupEntityManager->remove($storedGame);
                }
            }

            $cleanupEntityManager->flush();
        }
    }
}
