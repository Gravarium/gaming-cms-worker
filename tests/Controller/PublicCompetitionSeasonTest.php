<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionSeason;
use App\Entity\Game;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicCompetitionSeasonTest extends WebTestCase
{
    public function testOnlySeasonsWithVisibleCompetitionsAppearAndDetailDoesNotLeakPrivateEntries(): void
    {
        $client = static::createClient();
        [$game, $publicSeason, $hiddenSeason, $competitions] = $this->fixtures($client);

        try {
            $client->request('GET', '/competition-seasons');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/competition-seasons/'.$publicSeason->getId().'"]');
            self::assertSelectorNotExists('a[href="/competition-seasons/'.$hiddenSeason->getId().'"]');

            $client->request('GET', '/competition-seasons/'.$publicSeason->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/competitions/'.$competitions[0]->getId().'"]');
            self::assertSelectorNotExists('a[href="/competitions/'.$competitions[1]->getId().'"]');

            $client->request('GET', '/competition-seasons/'.$hiddenSeason->getId());
            self::assertResponseStatusCodeSame(404);

            $em = $this->em($client);
            $managedGame = $em->find(Game::class, $game->getId());
            self::assertInstanceOf(Game::class, $managedGame);
            $managedGame->setEnabled(false);
            $em->flush();
            $client->request('GET', '/competition-seasons/'.$publicSeason->getId());
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, $game, [$publicSeason, $hiddenSeason], $competitions);
        }
    }

    public function testMalformedAndOutOfRangePagesFailClosed(): void
    {
        $client = static::createClient();
        foreach (['?page=0', '?page=-1', '?page=10000', '?page[]=1', '?page=1x'] as $query) {
            $client->request('GET', '/competition-seasons'.$query);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testGamingModuleGateAppliesToBothPages(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $state = $em->find(CmsModuleState::class, 'gaming');
        $created = $state === null;
        $wasEnabled = $state?->isEnabled() ?? true;
        $state ??= (new CmsModuleState())->setModuleKey('gaming');
        $state->setEnabled(false);
        $em->persist($state);
        $em->flush();

        try {
            $client->request('GET', '/competition-seasons');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/competition-seasons/1');
            self::assertResponseStatusCodeSame(404);
        } finally {
            if ($created) {
                $em->remove($state);
            } else {
                $state->setEnabled($wasEnabled);
            }
            $em->flush();
        }
    }

    /** @return array{Game, CompetitionSeason, CompetitionSeason, list<Competition>} */
    private function fixtures(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(6));
        $game = (new Game())->setName('Season game '.$suffix)->setSlug('season-game-'.$suffix);
        $public = (new CompetitionSeason())->setGame($game)->setName('Visible season '.$suffix);
        $hidden = (new CompetitionSeason())->setGame($game)->setName('Hidden season '.$suffix);
        $visible = (new Competition())->setGame($game)->setSeason($public)
            ->setName('Visible cup '.$suffix)->setSlug('visible-cup-'.$suffix)->open();
        $private = (new Competition())->setGame($game)->setSeason($public)
            ->setName('Private cup '.$suffix)->setSlug('private-cup-'.$suffix)
            ->setVisibility(Competition::VISIBILITY_PRIVATE)->open();
        $draft = (new Competition())->setGame($game)->setSeason($hidden)
            ->setName('Draft cup '.$suffix)->setSlug('draft-cup-'.$suffix);
        $em = $this->em($client);
        foreach ([$game, $public, $hidden, $visible, $private, $draft] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [$game, $public, $hidden, [$visible, $private, $draft]];
    }

    /**
     * @param list<CompetitionSeason> $seasons
     * @param list<Competition> $competitions
     */
    private function cleanup(KernelBrowser $client, Game $game, array $seasons, array $competitions): void
    {
        $em = $this->em($client);
        foreach ($competitions as $competition) {
            $managed = $em->find(Competition::class, $competition->getId());
            if ($managed instanceof Competition) { $em->remove($managed); }
        }
        foreach ($seasons as $season) {
            $managed = $em->find(CompetitionSeason::class, $season->getId());
            if ($managed instanceof CompetitionSeason) { $em->remove($managed); }
        }
        $managedGame = $em->find(Game::class, $game->getId());
        if ($managedGame instanceof Game) { $em->remove($managedGame); }
        $em->flush();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
