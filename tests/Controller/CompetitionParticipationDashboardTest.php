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
            $this->assertPersonalizedResponseIsNotCacheable($client);

            $token = $this->withdrawalToken($client, (int) $competition->getId(), (int) $participant->getId());
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
                $this->entityManager($client)->getRepository(CompetitionParticipant::class)->find($participant->getId())?->getStatus(),
            );
            $this->assertPersonalizedResponseIsNotCacheable($client);
        } finally {
            try {
                $this->removeFixtures($this->entityManager($client), $fixtures);
            } finally {
                $this->restoreModuleStates($client, $moduleSnapshot);
            }
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
            $participant = $this->participant($entityManager, $competition, $visitor, 'Captain team '.$suffix);
            $fixtures[] = $participant;
            $otherCompetition = $this->competition($entityManager, $game, 'Competition B '.$suffix, $suffix.'-b');
            $fixtures[] = $otherCompetition;
            $entityManager->flush();

            $client->loginUser($visitor);
            $client->request('GET', '/account/competitions');
            $token = $this->withdrawalToken($client, (int) $competition->getId(), (int) $participant->getId());

            $client->request('POST', '/account/competitions/'.$otherCompetition->getId().'/participant/'.$participant->getId().'/withdraw', [
                '_token' => $token,
            ]);
            self::assertResponseStatusCodeSame(404);

            $currentEntityManager = $this->entityManager($client);
            $managedParticipant = $currentEntityManager->getRepository(CompetitionParticipant::class)->find($participant->getId());
            self::assertInstanceOf(CompetitionParticipant::class, $managedParticipant);
            $managedParticipant->setCaptain($captain);
            $currentEntityManager->flush();

            $client->request('POST', '/account/competitions/'.$competition->getId().'/participant/'.$participant->getId().'/withdraw', [
                '_token' => $token,
            ]);
            self::assertResponseStatusCodeSame(404);
            self::assertSame(
                CompetitionParticipant::STATUS_REGISTERED,
                $this->entityManager($client)->getRepository(CompetitionParticipant::class)->find($participant->getId())?->getStatus(),
            );
        } finally {
            try {
                $this->removeFixtures($this->entityManager($client), $fixtures);
            } finally {
                $this->restoreModuleStates($client, $moduleSnapshot);
            }
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
            $fixtures[] = $closedCompetition;
            $closedParticipant = $this->participant($entityManager, $closedCompetition, $captain, 'Still registered '.$suffix);
            $fixtures[] = $closedParticipant;
            $checkedInCompetition = $this->competition($entityManager, $game, 'Check-in '.$suffix, $suffix.'-checkin');
            $fixtures[] = $checkedInCompetition;
            $checkedInParticipant = $this->participant($entityManager, $checkedInCompetition, $captain, 'Checked in '.$suffix);
            $fixtures[] = $checkedInParticipant;
            $entityManager->flush();

            $client->loginUser($captain);
            $client->request('GET', '/account/competitions');
            $closedId = (int) $closedCompetition->getId();
            $closedParticipantId = (int) $closedParticipant->getId();
            $checkedInId = (int) $checkedInCompetition->getId();
            $checkedInParticipantId = (int) $checkedInParticipant->getId();
            $closedToken = $this->withdrawalToken($client, $closedId, $closedParticipantId);
            $checkedInToken = $this->withdrawalToken($client, $checkedInId, $checkedInParticipantId);

            $currentEntityManager = $this->entityManager($client);
            $managedClosedCompetition = $currentEntityManager->getRepository(Competition::class)->find($closedId);
            $managedCheckedInParticipant = $currentEntityManager->getRepository(CompetitionParticipant::class)->find($checkedInParticipantId);
            self::assertInstanceOf(Competition::class, $managedClosedCompetition);
            self::assertInstanceOf(CompetitionParticipant::class, $managedCheckedInParticipant);
            $managedClosedCompetition->start();
            $managedCheckedInParticipant->checkIn();
            $currentEntityManager->flush();

            $client->request('POST', '/account/competitions/'.$checkedInId.'/participant/'.$checkedInParticipantId.'/withdraw', [
                '_token' => 'invalid-token',
            ]);
            self::assertResponseStatusCodeSame(403);
            self::assertSame(
                CompetitionParticipant::STATUS_CHECKED_IN,
                $this->entityManager($client)->getRepository(CompetitionParticipant::class)->find($checkedInParticipantId)?->getStatus(),
            );

            foreach ([
                [$closedId, $closedParticipantId, $closedToken],
                [$checkedInId, $checkedInParticipantId, $checkedInToken],
            ] as [$competitionId, $participantId, $token]) {
                $client->request('POST', '/account/competitions/'.$competitionId.'/participant/'.$participantId.'/withdraw', [
                    '_token' => $token,
                ]);
                self::assertResponseRedirects('/account/competitions');
                $client->followRedirect();
                self::assertSelectorTextContains('body', 'nur während der offenen Registrierung');
            }

            self::assertSame(
                CompetitionParticipant::STATUS_REGISTERED,
                $this->entityManager($client)->getRepository(CompetitionParticipant::class)->find($closedParticipantId)?->getStatus(),
            );
            self::assertSame(
                CompetitionParticipant::STATUS_CHECKED_IN,
                $this->entityManager($client)->getRepository(CompetitionParticipant::class)->find($checkedInParticipantId)?->getStatus(),
            );
        } finally {
            try {
                $this->removeFixtures($this->entityManager($client), $fixtures);
            } finally {
                $this->restoreModuleStates($client, $moduleSnapshot);
            }
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
                $this->entityManager($client)->getRepository(CompetitionParticipant::class)->find($participant->getId())?->getStatus(),
            );
        } finally {
            try {
                $this->removeFixtures($this->entityManager($client), $fixtures);
            } finally {
                $this->restoreModuleStates($client, $moduleSnapshot);
            }
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

    private function withdrawalToken(KernelBrowser $client, int $competitionId, int $participantId): string
    {
        $action = '/account/competitions/'.$competitionId.'/participant/'.$participantId.'/withdraw';
        $input = $client->getCrawler()->filter('form[action="'.$action.'"]')->filter('input[name="_token"]');
        self::assertCount(1, $input);
        $token = $input->attr('value');
        if (!is_string($token)) {
            throw new \LogicException('The withdrawal form does not contain a CSRF token.');
        }

        return $token;
    }

    /** @param list<object> $fixtures */
    private function removeFixtures(EntityManagerInterface $entityManager, array $fixtures): void
    {
        foreach (array_reverse($fixtures) as $fixture) {
            $identifiers = $entityManager->getClassMetadata($fixture::class)->getIdentifierValues($fixture);
            if (count($identifiers) !== 1) {
                continue;
            }
            $identifier = array_values($identifiers)[0] ?? null;
            if ($identifier === null) {
                continue;
            }
            $managed = $entityManager->find($fixture::class, $identifier);
            if ($managed !== null) {
                $entityManager->remove($managed);
            }
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

    private function assertPersonalizedResponseIsNotCacheable(KernelBrowser $client): void
    {
        $directives = array_map('trim', explode(',', strtolower((string) $client->getResponse()->headers->get('Cache-Control'))));
        self::assertContains('private', $directives);
        self::assertContains('no-store', $directives);
        self::assertContains('max-age=0', $directives);
    }
}
