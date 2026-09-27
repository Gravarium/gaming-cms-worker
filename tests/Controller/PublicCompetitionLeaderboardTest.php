<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicCompetitionLeaderboardTest extends WebTestCase
{
    public function testPublicBoardRanksConfirmedResultsAndHidesPendingScoresAndPrivateFields(): void
    {
        $client = static::createClient();
        $fixture = $this->competition($client);

        $client->request('GET', '/competitions/'.$fixture['competition']->getId().'/leaderboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ergebnisse');
        self::assertStringContainsString('Alpha', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Beta', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('2 : 1', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('9 : 0', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($fixture['userA']->getEmail(), (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString($fixture['userB']->getEmail(), (string) $client->getResponse()->getContent());
        self::assertSelectorExists('a[href="/competitions/'.$fixture['competition']->getId().'"]');
    }

    public function testPrivateCompetitionIsNotDisclosed(): void
    {
        $client = static::createClient();
        $fixture = $this->competition($client);
        $fixture['competition']->setVisibility(Competition::VISIBILITY_PRIVATE);
        $this->em($client)->flush();

        $client->request('GET', '/competitions/'.$fixture['competition']->getId().'/leaderboard');

        self::assertResponseStatusCodeSame(404);
    }

    public function testBoardFailsClosedWhenGamingModuleIsDisabled(): void
    {
        $client = static::createClient();
        $fixture = $this->competition($client);
        $entityManager = $this->em($client);
        $state = $entityManager->find(CmsModuleState::class, 'gaming');
        $created = $state === null;
        $wasEnabled = $state?->isEnabled() ?? true;
        $state ??= (new CmsModuleState())->setModuleKey('gaming');
        $state->setEnabled(false);
        $entityManager->persist($state);
        $entityManager->flush();

        try {
            $client->request('GET', '/competitions/'.$fixture['competition']->getId().'/leaderboard');
            self::assertResponseStatusCodeSame(404);
        } finally {
            if ($created) {
                $entityManager->remove($state);
            } else {
                $state->setEnabled($wasEnabled);
            }
            $entityManager->flush();
        }
    }

    /** @return array{competition: Competition, userA: User, userB: User} */
    private function competition(KernelBrowser $client): array
    {
        $entityManager = $this->em($client);
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())->setName('Arena '.$suffix)->setSlug('arena-'.$suffix);
        $userA = $this->user('alpha-'.$suffix);
        $userB = $this->user('beta-'.$suffix);
        $competition = (new Competition())
            ->setGame($game)
            ->setCreatedBy($userA)
            ->setName('Public Cup '.$suffix)
            ->setSlug('public-cup-'.$suffix);
        $competition->open()->start();

        $participantA = (new CompetitionParticipant())
            ->setCompetition($competition)
            ->setCaptain($userA)
            ->setName('Alpha');
        $participantB = (new CompetitionParticipant())
            ->setCompetition($competition)
            ->setCaptain($userB)
            ->setName('Beta');
        $participantA->checkIn();
        $participantB->checkIn();

        $confirmed = (new CompetitionMatch())
            ->setCompetition($competition)
            ->setParticipants($participantA, $participantB)
            ->markReady();
        $confirmed->submitResult($participantA, 2, 1, $userA);
        $confirmed->confirmResult($participantB);

        $pending = (new CompetitionMatch())
            ->setCompetition($competition)
            ->setRoundNumber(2)
            ->setSequence(2)
            ->setParticipants($participantA, $participantB)
            ->markReady();
        $pending->submitResult($participantA, 9, 0, $userA);

        foreach ([$game, $userA, $userB, $competition, $participantA, $participantB, $confirmed, $pending] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        return ['competition' => $competition, 'userA' => $userA, 'userB' => $userB];
    }

    private function user(string $name): User
    {
        return (new User())
            ->setEmail($name.'-'.bin2hex(random_bytes(4)).'@example.test')
            ->setDisplayName($name)
            ->verifyEmail();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
