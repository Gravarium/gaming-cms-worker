<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildMember;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicGuildRosterPrivacyTest extends WebTestCase
{
    public function testPublicRosterShowsOnlyActiveMembersOfTheRequestedGuild(): void
    {
        $client = static::createClient();
        $token = bin2hex(random_bytes(6));

        $gameSlug = 'roster-game-'.$token;
        $targetGuildSlug = 'roster-target-'.$token;
        $foreignGuildSlug = 'roster-foreign-'.$token;
        $activeCharacter = 'Active character '.$token;
        $activePlayer = 'Active player '.$token;
        $inactiveCharacter = 'Inactive character '.$token;
        $inactivePlayer = 'Inactive player '.$token;
        $foreignCharacter = 'Foreign character '.$token;
        $foreignPlayer = 'Foreign player '.$token;

        $game = (new Game())->setName('Roster game '.$token)->setSlug($gameSlug);
        $targetGuild = (new Guild())
            ->setGame($game)
            ->setName('Target guild '.$token)
            ->setSlug($targetGuildSlug)
            ->setServerName('Synthetic server')
            ->setDescription('Target roster fixture');
        $foreignGuild = (new Guild())
            ->setGame($game)
            ->setName('Foreign guild '.$token)
            ->setSlug($foreignGuildSlug)
            ->setServerName('Synthetic server')
            ->setDescription('Foreign roster fixture');

        $activeMember = (new GuildMember())
            ->setGuild($targetGuild)
            ->setCharacterName($activeCharacter)
            ->setPlayerName($activePlayer);
        $inactiveMember = (new GuildMember())
            ->setGuild($targetGuild)
            ->setCharacterName($inactiveCharacter)
            ->setPlayerName($inactivePlayer)
            ->setActive(false);
        $foreignMember = (new GuildMember())
            ->setGuild($foreignGuild)
            ->setCharacterName($foreignCharacter)
            ->setPlayerName($foreignPlayer);

        $memberNames = [$activeCharacter, $inactiveCharacter, $foreignCharacter];
        $guildSlugs = [$targetGuildSlug, $foreignGuildSlug];

        try {
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            foreach ([$game, $targetGuild, $foreignGuild, $activeMember, $inactiveMember, $foreignMember] as $entity) {
                $entityManager->persist($entity);
            }
            $entityManager->flush();

            $client->request('GET', '/gaming/guild/'.$targetGuildSlug);
            self::assertResponseIsSuccessful();

            $body = (string) $client->getResponse()->getContent();
            self::assertStringContainsString($activeCharacter, $body);
            self::assertStringContainsString($activePlayer, $body);
            self::assertStringNotContainsString($inactiveCharacter, $body);
            self::assertStringNotContainsString($inactivePlayer, $body);
            self::assertStringNotContainsString($foreignCharacter, $body);
            self::assertStringNotContainsString($foreignPlayer, $body);
        } finally {
            $cleanupEntityManager = $client->getContainer()->get(EntityManagerInterface::class);
            $cleanupEntityManager->clear();

            foreach ($memberNames as $characterName) {
                $storedMember = $cleanupEntityManager->getRepository(GuildMember::class)->findOneBy(['characterName' => $characterName]);
                if ($storedMember instanceof GuildMember) {
                    $cleanupEntityManager->remove($storedMember);
                }
            }

            foreach ($guildSlugs as $slug) {
                $storedGuild = $cleanupEntityManager->getRepository(Guild::class)->findOneBy(['slug' => $slug]);
                if ($storedGuild instanceof Guild) {
                    $cleanupEntityManager->remove($storedGuild);
                }
            }

            $storedGame = $cleanupEntityManager->getRepository(Game::class)->findOneBy(['slug' => $gameSlug]);
            if ($storedGame instanceof Game) {
                $cleanupEntityManager->remove($storedGame);
            }

            $cleanupEntityManager->flush();
        }
    }
}
