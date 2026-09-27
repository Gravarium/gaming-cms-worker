<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CompetitionParticipationDashboardTest extends WebTestCase
{
    private const MODULES = ['content', 'gaming'];

    public function testCaptainCanWithdrawAnOpenRegistrationFromTheirDashboard(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureModuleStates($client);
        $this->setModulesEnabled($client, true);
        $entityManager = $this->entityManager($client);
        $fixtures = [];

        try {
            $suffix = bin2hex(random_bytes(5));
            $captain = $this->user($entityManager, 'captain-'.$suffix);
            $fixtures[] = $captain;
            $otherCaptain = $this->user($entityManager, 'other-'.$suffix);
            $fixtures[] = $otherCaptain;
            $game = $this->game($entityManager, $suffix);
            $fixtures[] = $game;
            $competition = $this->competition($entityManager, $game, 'Own '.$suffix, $suffix.'-own');
            $fixtures[] = $competition;
            $participant = $this->participant($entityManager, $competition, $captain, 'My team '.$suffix);
            $fixtures[] = $participant;
            $otherCompetition = $this->competition($entityManager, $game, 'Other '.$suffix, $suffix.'-other');
            $fixtures[] = $otherCompetition;
            $otherParticipant = $this->participant($entityManager, $otherCompetition, $otherCaptain, 'Other team '.$suffix);
            $fixtures[] = $otherParticipant;
            $entityManager->flush();

            $client->loginUser($captain);
            $client->request('GET', '/account/competitions');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Own '.$suffix);
            self::assertSelectorTextContains('main', 'My team '.$suffix);
            self::assertSelectorTextNotContains('main', 'Other '.$suffix);
            self::assertSelectorTextNotContains('main', 'Other team '.$suffix);
            self::assertSame('private, no-store, max-age=0', $client->getResponse()->headers->get('Cache-Control'));

            $token = $client->getContainer()->get(CsrfTokenManagerInterface::class)
                ->getToken('competition-withdraw-'.$participant->getId())->getValue();
            $client->request('POST', '/account/competitions/'.$competition->getId().'/participant/'.$participant->getId().'/withdraw', [
                '_token' => $token,
            ]);

            self::assertResponseRedirects('/account/competitions');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Zurückgezogen');
            self::assertSelectorTextContains('main', 'Deine Anmeldung wurde zurückgezogen.');
            self::assertSame(
                CompetitionParticipant::STATUS_WITHDRAWN,
                $entityManager->getRepository(CompetitionParticipant::class)->find($participant->getId())?->getStatus(),
            );
            self::assertSame('private, no-store, max-age=0', $client->getResponse()->headers->get('Cache-Control'));
        } finally {
            $this->removeFixtures($entityManager, $fixtures);
            $this->restoreModuleStates($client, $moduleSnapshot);
        }
    }

    public function testOnlyTheCaptainCanWithdrawAndRouteIdsMustIdentifyTheSameCompetition(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureModuleStates($client);
        $this->setModulesEnabled($client, true);
        $entityManager = $this->entityManager($client);
        $fixtures = [];

        try {
            $suffix = bin2hex(random_bytes(5));
            $captain = $this->user($entityManager, 'captain-'.$suffix);
            $fixtures[] = $captain;
            $visitor = $this->user($entityManager, 'visitor-'.$suffix);
            $fixtures[] = $visitor;
            $game = $this->game($entityManager, $suffix);
            $fixtures[] = $game;
            $competition = $this->competition($entityManager, $game, 'Competition A '.$suffix, $suffix.'-a');
            $fixtures[] = $competition;
            $participant = $this->participant($entityManager, $competition, $captain, 'Captain team '.$suffix);
            $fixtures[] = $participant;
            $otherCompetition = $this->competition($entityManager, $game, 'Competition B '.$suffix, $suffix.'-b');
            $fixtures[] = $otherCompetition;
            $entityManager->flush();

            $client->loginUser($visitor);
            $token = $client->getContainer()->get(CsrfTokenManagerInterface::class)
                ->getToken('competition-withdraw-'.$participant->getId())->getValue();
            $client->request('POST', '/account/competitions/'.$competition->getId().'/participant/'.$participant->getId().'/withdraw', [
                '_token' => $token,
            ]);
            self::assertResponseStatusCodeSame(404);

            $client->loginUser($captain);
            $client->request('POST', '/account/competitions/'.$otherCompetition->getId().'/participant/'.$participant->getId().'/withdraw', [
                '_token' => $token,
            ]);
            self::assertResponseStatusCodeSame(404);
            self::assertSame(
                CompetitionParticipant::STATUS_REGISTERED,
                $entityManager->getRepository(CompetitionParticipant::class)->find($participant->getId())?->getStatus(),
            );
        } finally {
            $this->removeFixtures($entityManager, $fixtures);
            $this->restoreModuleStates($client, $moduleSnapshot);
        }
    }

    public function testInvalidCsrfAndClosedOrCheckedInEntriesCannotBeWithdrawn(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureModuleStates($client);
        $this->setModulesEnabled($client, true);
        $entityManager = $this->entityManager($client);
        $fixtures = [];

        try {
            $suffix = bin2hex(random_bytes(5));
            $captain = $this->user($entityManager, 'captain-'.$suffix);
            $fixtures[] = $captain;
            $game = $this->game($entityManager, $suffix);
            $fixtures[] = $game;
            $closedCompetition = $this->competition($entityManager, $game, 'Started '.$suffix, $suffix.'-started');
            $closedCompetition->start();
            $fixtures[] = $closedCompetition;
            $closedParticipant = $this->participant($entityManager, $closedCompetition, $captain, 'Still registered '.$suffix);
            $fixtures[] = $closedParticipant;
            $checkedInCompetition = $this->competition($entityManager, $game, 'Check-in '.$suffix, $suffix.'-checkin');
            $fixtures[] = $checkedInCompetition;
            $checkedInParticipant = $this->participant($entityManager, $checkedInCompetition, $captain, 'Checked in '.$suffix);
            $checkedInParticipant->checkIn();
            $fixtures[] = $checkedInParticipant;
            $entityManager->flush();

            $client->loginUser($captain);
            $client->request('POST', '/account/competitions/'.$checkedInCompetition->getId().'/participant/'.$checkedInParticipant->getId().'/withdraw', [
                '_token' => 'invalid-token',
            ]);
            self::assertResponseStatusCodeSame(403);
            self::assertSame(
                CompetitionParticipant::STATUS_CHECKED_IN,
                $entityManager->getRepository(CompetitionParticipant::class)->find($checkedInParticipant->getId())?->getStatus(),
            );

            foreach ([[$closedCompetition, $closedParticipant], [$checkedInCompetition, $checkedInParticipant]] as [$competition, $participant]) {
                $token = $client->getContainer()->get(CsrfTokenManagerInterface::class)
                    ->getToken('competition-withdraw-'.$participant->getId())->getValue();
                $client->request('POST', '/account/competitions/'.$competition->getId().'/participant/'.$participant->getId().'/withdraw', [
                    '_token' => $token,
                ]);
                self::assertResponseRedirects('/account/competitions');
                $client->followRedirect();
                self::assertSelectorTextContains('body', 'nur während der offenen Registrierung');
            }

            self::assertSame(
                CompetitionParticipant::STATUS_REGISTERED,
                $entityManager->getRepository(CompetitionParticipant::class)->find($closedParticipant->getId())?->getStatus(),
            );
            self::assertSame(
                CompetitionParticipant::STATUS_CHECKED_IN,
                $entityManager->getRepository(CompetitionParticipant::class)->find($checkedInParticipant->getId())?->getStatus(),
            );
        } finally {
            $this->removeFixtures($entityManager, $fixtures);
            $this->restoreModuleStates($client, $moduleSnapshot);
        }
    }

    public function testDisabledGamingHidesTheDashboardAndRejectsWithdrawal(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureModuleStates($client);
        $this->setModulesEnabled($client, true);
        $entityManager = $this->entityManager($client);
        $fixtures = [];

        try {
            $suffix = bin2hex(random_bytes(5));
            $captain = $this->user($entityManager, 'captain-'.$suffix);
            $fixtures[] = $captain;
            $game = $this->game($entityManager, $suffix);
            $fixtures[] = $game;
            $competition = $this->competition($entityManager, $game, 'Disabled '.$suffix, $suffix.'-disabled');
            $fixtures[] = $competition;
            $participant = $this->participant($entityManager, $competition, $captain, 'Disabled entry '.$suffix);
            $fixtures[] = $participant;
            $entityManager->flush();
            $client->loginUser($captain);

            $this->setModuleEnabled($entityManager, 'gaming', false);
            $client->request('GET', '/account/competitions');
            self::assertResponseStatusCodeSame(404);
            $client->request('POST', '/account/competitions/'.$competition->getId().'/participant/'.$participant->getId().'/withdraw', [
                '_token' => 'irrelevant-while-disabled',
            ]);
            self::assertResponseStatusCodeSame(404);
            self::assertSame(
                CompetitionParticipant::STATUS_REGISTERED,
                $entityManager->getRepository(CompetitionParticipant::class)->find($participant->getId())?->getStatus(),
            );
        } finally {
            $this->removeFixtures($entityManager, $fixtures);
            $this->restoreModuleStates($client, $moduleSnapshot);
        }
    }

    private function user(EntityManagerInterface $entityManager, string $suffix): User
    {
        $user = (new User())
            ->setEmail('competition-'.$suffix.'@example.test')
            ->setDisplayName('Competition '.$suffix)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $entityManager->persist($user);

        return $user;
    }

    private function game(EntityManagerInterface $entityManager, string $suffix): Game
    {
        $game = (new Game())->setName('Game '.$suffix)->setSlug('game-'.$suffix);
        $entityManager->persist($game);

        return $game;
    }

    private function competition(EntityManagerInterface $entityManager, Game $game, string $name, string $slug): Competition
    {
        $competition = (new Competition())
            ->setGame($game)
            ->setName($name)
            ->setSlug('competition-'.$slug);
        $competition->open();
        $entityManager->persist($competition);

        return $competition;
    }

    private function participant(EntityManagerInterface $entityManager, Competition $competition, User $captain, string $name): CompetitionParticipant
    {
        $participant = (new CompetitionParticipant())
            ->setCompetition($competition)
            ->setCaptain($captain)
            ->setName($name);
        $entityManager->persist($participant);

        return $participant;
    }

    /** @param list<object> $fixtures */
    private function removeFixtures(EntityManagerInterface $entityManager, array $fixtures): void
    {
        foreach (array_reverse($fixtures) as $fixture) {
            $entityManager->remove($fixture);
        }
        if ($fixtures !== []) {
            $entityManager->flush();
        }
        $entityManager->clear();
    }

    /** @return array<string, array{exists: bool, enabled: bool}> */
    private function captureModuleStates(KernelBrowser $client): array
    {
        $entityManager = $this->entityManager($client);
        $snapshot = [];
        foreach (self::MODULES as $key) {
            $state = $entityManager->find(CmsModuleState::class, $key);
            $snapshot[$key] = ['exists' => $state instanceof CmsModuleState, 'enabled' => $state?->isEnabled() ?? true];
        }

        return $snapshot;
    }

    private function setModulesEnabled(KernelBrowser $client, bool $enabled): void
    {
        $entityManager = $this->entityManager($client);
        foreach (self::MODULES as $key) {
            $this->setModuleEnabled($entityManager, $key, $enabled);
        }
    }

    private function setModuleEnabled(EntityManagerInterface $entityManager, string $key, bool $enabled): void
    {
        $state = $entityManager->find(CmsModuleState::class, $key);
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey($key)->updateVersion('test');
            $entityManager->persist($state);
        }
        $state->setEnabled($enabled);
        $entityManager->flush();
    }

    /** @param array<string, array{exists: bool, enabled: bool}> $snapshot */
    private function restoreModuleStates(KernelBrowser $client, array $snapshot): void
    {
        $entityManager = $this->entityManager($client);
        foreach ($snapshot as $key => $state) {
            $current = $entityManager->find(CmsModuleState::class, $key);
            if (!$state['exists']) {
                if ($current instanceof CmsModuleState) {
                    $entityManager->remove($current);
                }
                continue;
            }
            if ($current instanceof CmsModuleState) {
                $current->setEnabled($state['enabled']);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
