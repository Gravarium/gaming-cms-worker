<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Competition\CompetitionSeason;
use App\Entity\Game;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminCompetitionSeasonTest extends WebTestCase
{
    public function testManagerCreatesSeasonButInvalidDatesAndCsrfDoNotPersist(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $module = $em->find(CmsModuleState::class, 'gaming');
        $previous = $module?->isEnabled();
        if (!$module instanceof CmsModuleState) {
            $module = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('test');
            $em->persist($module);
        }
        $module->setEnabled(true);
        $suffix = bin2hex(random_bytes(6));
        $manager = (new User())->setEmail('season-manager-'.$suffix.'@example.test')
            ->setDisplayName('Season manager')->setPermissions(['CMS_GAMING_MANAGE'])->verifyEmail();
        $outsider = (new User())->setEmail('season-outsider-'.$suffix.'@example.test')
            ->setDisplayName('Season outsider')->verifyEmail();
        $game = (new Game())->setName('Season game '.$suffix)->setSlug('season-game-'.$suffix);
        foreach ([$manager, $outsider, $game] as $fixture) { $em->persist($fixture); }
        $em->flush();

        try {
            $client->request('GET', '/admin/gaming/competition-seasons');
            self::assertResponseRedirects('/login');

            $client->loginUser($outsider);
            $client->request('GET', '/admin/gaming/competition-seasons');
            self::assertResponseStatusCodeSame(403);

            $client->restart();
            $em = $client->getContainer()->get(EntityManagerInterface::class);
            $client->loginUser($manager);
            $client->request('GET', '/admin/gaming/competition-seasons');
            self::assertResponseIsSuccessful();
            $client->submitForm('Saison anlegen', [
                'competition_season[name]' => 'Spring '.$suffix,
                'competition_season[game]' => (string) $game->getId(),
                'competition_season[startsAt]' => '2030-03-01T12:00',
                'competition_season[endsAt]' => '2030-02-01T12:00',
            ]);
            self::assertSelectorTextContains('body', 'Das Ende muss nach dem Beginn liegen.');
            self::assertNull($em->getRepository(CompetitionSeason::class)->findOneBy(['name' => 'Spring '.$suffix]));

            $client->request('POST', '/admin/gaming/competition-seasons', [
                'competition_season' => [
                    'name' => 'Forged '.$suffix,
                    'game' => (string) $game->getId(),
                    'startsAt' => '2030-03-01T12:00',
                    '_token' => 'invalid',
                ],
            ]);
            self::assertNull($em->getRepository(CompetitionSeason::class)->findOneBy(['name' => 'Forged '.$suffix]));

            $client->request('GET', '/admin/gaming/competition-seasons');
            $client->submitForm('Saison anlegen', [
                'competition_season[name]' => 'Spring '.$suffix,
                'competition_season[game]' => (string) $game->getId(),
                'competition_season[startsAt]' => '2030-03-01T12:00',
                'competition_season[endsAt]' => '2030-06-01T12:00',
            ]);
            self::assertResponseRedirects('/admin/gaming/competition-seasons');
            $season = $em->getRepository(CompetitionSeason::class)->findOneBy(['name' => 'Spring '.$suffix]);
            self::assertInstanceOf(CompetitionSeason::class, $season);
            self::assertSame($game->getId(), $season->getGame()?->getId());

            $module = $em->find(CmsModuleState::class, 'gaming');
            self::assertInstanceOf(CmsModuleState::class, $module);
            $module->setEnabled(false);
            $em->flush();
            $client->request('GET', '/admin/gaming/competition-seasons');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $em = $client->getContainer()->get(EntityManagerInterface::class);
            $season = $em->getRepository(CompetitionSeason::class)->findOneBy(['name' => 'Spring '.$suffix]);
            if ($season instanceof CompetitionSeason) { $em->remove($season); }
            $managedGame = $em->find(Game::class, $game->getId());
            if ($managedGame instanceof Game) { $em->remove($managedGame); }
            foreach ([$manager, $outsider] as $user) {
                $managedUser = $em->find(User::class, $user->getId());
                if ($managedUser instanceof User) { $em->remove($managedUser); }
            }
            $module = $em->find(CmsModuleState::class, 'gaming');
            if ($module instanceof CmsModuleState) {
                if ($previous === null) { $em->remove($module); }
                else { $module->setEnabled($previous); }
            }
            $em->flush();
        }
    }
}
