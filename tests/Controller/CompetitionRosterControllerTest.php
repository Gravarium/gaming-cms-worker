<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\CompetitionRoster\CompetitionRosterInviteLink;
use App\Entity\CmsModuleState;
use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\Game;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class CompetitionRosterControllerTest extends WebTestCase
{
    private const MODULES = ['content', 'gaming'];

    public function testCaptainCreatesBoundSingleUseInvitationsAndTeamCapacityCannotBeExceeded(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureModuleStates($client);
        $this->setModulesEnabled($client, true);
        $entityManager = $this->entityManager($client);
        $fixtures = [];

        try {
            $suffix = bin2hex(random_bytes(5));
            $captain = $this->user($entityManager, 'captain-'.$suffix);
            $inviteeA = $this->user($entityManager, 'invitee-a-'.$suffix);
            $inviteeB = $this->user($entityManager, 'invitee-b-'.$suffix);
            $inviteeC = $this->user($entityManager, 'invitee-c-'.$suffix);
            $wrongUser = $this->user($entityManager, 'wrong-'.$suffix);
            array_push($fixtures, $captain, $inviteeA, $inviteeB, $inviteeC, $wrongUser);
            $game = $this->game($entityManager, $suffix);
            $fixtures[] = $game;
            $competition = $this->competition($entityManager, $game, $suffix, Competition::MODE_TEAM, 3);
            $fixtures[] = $competition;
            $entityManager->flush();
            $participant = $this->participant($entityManager, $competition, $captain, 'Team '.$suffix, [$captain]);
            $fixtures[] = $participant;
            $entityManager->flush();

            $client->loginUser($captain);
            $crawler = $client->request('GET', '/account/competition-rosters');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Team '.$suffix);
            self::assertSelectorTextContains('body', $captain->getDisplayName());
            $this->assertPrivateResponse($client);

            $inviteAction = '/account/competition-rosters/'.$competition->getId().'/participants/'.$participant->getId().'/invite';
            $client->loginUser($wrongUser);
            $client->request('POST', $inviteAction, [
                '_token' => $this->csrfToken($client, 'competition-roster-invite-'.$participant->getId()),
                'email' => $inviteeA->getEmail(),
            ]);
            self::assertResponseStatusCodeSame(404);

            $client->loginUser($captain);
            $pathA = $this->createInvite($client, $competition, $participant, $inviteeA->getEmail());
            $pathB = $this->createInvite($client, $competition, $participant, $inviteeB->getEmail());
            self::assertNotSame($pathA, $pathB);
            self::assertStringNotContainsString($inviteeA->getEmail(), $pathA);
            self::assertStringNotContainsString($inviteeB->getEmail(), $pathB);
            $token = basename($pathA);
            $encodedPayload = explode('.', $token, 2)[0];
            $decodedPayload = base64_decode(strtr($encodedPayload, '-_', '+/').str_repeat('=', (4 - strlen($encodedPayload) % 4) % 4), true);
            self::assertIsString($decodedPayload);
            $decodedClaims = json_decode($decodedPayload, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($decodedClaims)) {
                throw new \LogicException('The invitation payload is not a JSON object.');
            }
            $issuedAt = $decodedClaims['issuedAt'] ?? null;
            $expiresAt = $decodedClaims['expiresAt'] ?? null;
            if (!is_int($issuedAt) || !is_int($expiresAt)) {
                throw new \LogicException('The invitation payload has no integer expiry.');
            }
            self::assertSame(3600, $expiresAt - $issuedAt);
            self::assertNotSame($inviteeA->getEmail(), $decodedClaims['email'] ?? null);

            $client->loginUser($wrongUser);
            $client->request('GET', $pathA);
            self::assertResponseStatusCodeSame(404);

            $client->loginUser($inviteeA);
            $client->request('GET', $pathA);
            self::assertResponseIsSuccessful();
            $client->request('POST', $pathA, ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);

            $crawler = $client->request('GET', $pathA);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Team '.$suffix);
            self::assertSelectorTextNotContains('body', $inviteeA->getEmail());
            $this->assertPrivateResponse($client);
            $form = $crawler->filter('form[method="post"]')->form();
            $client->submit($form);
            self::assertResponseRedirects('/account/competition-rosters/'.$competition->getId().'/participants/'.$participant->getId().'/join-result');
            $crawler = $client->followRedirect();
            self::assertSelectorTextContains('body', 'Du bist jetzt Mitglied des Competition-Teams.');
            $this->assertPrivateResponse($client);

            $entityManager = $this->entityManager($client);
            $entityManager->clear();
            $savedParticipant = $entityManager->find(CompetitionParticipant::class, $participant->getId());
            self::assertInstanceOf(CompetitionParticipant::class, $savedParticipant);
            self::assertCount(2, $savedParticipant->getRosterUserIds());
            self::assertContains($inviteeA->getId(), $savedParticipant->getRosterUserIds());
            self::assertTrue($savedParticipant->containsUser($inviteeA));

            // The second link was issued against the old roster fingerprint and cannot use a remaining slot.
            $client->loginUser($inviteeB);
            $client->request('GET', $pathB);
            self::assertResponseStatusCodeSame(404);

            // A fresh captain-issued link can fill the remaining slot.
            $client->loginUser($captain);
            $pathC = $this->createInvite($client, $competition, $participant, $inviteeC->getEmail());
            $client->loginUser($inviteeC);
            $crawler = $client->request('GET', $pathC);
            self::assertResponseIsSuccessful();
            $client->submit($crawler->filter('form[method="post"]')->form());
            self::assertResponseRedirects('/account/competition-rosters/'.$competition->getId().'/participants/'.$participant->getId().'/join-result');
            $client->followRedirect();

            // A successful link remains unusable after its one acceptance.
            $client->loginUser($inviteeA);
            $client->request('GET', $pathA);
            self::assertResponseStatusCodeSame(404);

            $entityManager->clear();
            $savedParticipant = $entityManager->find(CompetitionParticipant::class, $participant->getId());
            self::assertInstanceOf(CompetitionParticipant::class, $savedParticipant);
            self::assertCount(3, $savedParticipant->getRosterUserIds());
            self::assertContains($inviteeA->getId(), $savedParticipant->getRosterUserIds());
            self::assertContains($inviteeC->getId(), $savedParticipant->getRosterUserIds());
            self::assertLessThanOrEqual(3, count(array_unique($savedParticipant->getRosterUserIds())));
        } finally {
            try {
                $this->removeFixtures($this->entityManager($client), $fixtures);
            } finally {
                $this->restoreModuleStates($client, $moduleSnapshot);
            }
        }
    }

    public function testCaptainCanRemoveOnlyNonCaptainMembersBeforeCheckInAndRouteIdsAreBound(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureModuleStates($client);
        $this->setModulesEnabled($client, true);
        $entityManager = $this->entityManager($client);
        $fixtures = [];

        try {
            $suffix = bin2hex(random_bytes(5));
            $captain = $this->user($entityManager, 'remove-captain-'.$suffix);
            $member = $this->user($entityManager, 'remove-member-'.$suffix);
            $other = $this->user($entityManager, 'remove-other-'.$suffix);
            array_push($fixtures, $captain, $member, $other);
            $game = $this->game($entityManager, $suffix);
            $fixtures[] = $game;
            $competition = $this->competition($entityManager, $game, $suffix, Competition::MODE_TEAM, 3);
            $otherCompetition = $this->competition($entityManager, $game, $suffix.'-other', Competition::MODE_TEAM, 3);
            array_push($fixtures, $competition, $otherCompetition);
            $entityManager->flush();
            $participant = $this->participant($entityManager, $competition, $captain, 'Managed team '.$suffix, [$captain, $member]);
            $foreignParticipant = $this->participant($entityManager, $otherCompetition, $other, 'Foreign team '.$suffix, [$other]);
            array_push($fixtures, $participant, $foreignParticipant);
            $entityManager->flush();

            $client->loginUser($captain);
            $crawler = $client->request('GET', '/account/competition-rosters');
            self::assertResponseIsSuccessful();
            $removeAction = '/account/competition-rosters/'.$competition->getId().'/participants/'.$participant->getId().'/members/'.$member->getId().'/remove';
            $form = $crawler->filter('form[action="'.$removeAction.'"]')->form();
            $client->submit($form);
            self::assertResponseRedirects('/account/competition-rosters');
            $this->assertPrivateResponse($client);

            $entityManager = $this->entityManager($client);
            $entityManager->clear();
            $savedParticipant = $entityManager->find(CompetitionParticipant::class, $participant->getId());
            self::assertInstanceOf(CompetitionParticipant::class, $savedParticipant);
            self::assertSame([$captain->getId()], $savedParticipant->getRosterUserIds());

            $client->loginUser($other);
            $client->request('POST', $removeAction, [
                '_token' => $this->csrfToken($client, 'competition-roster-remove-'.$participant->getId().'-'.$member->getId()),
            ]);
            self::assertResponseStatusCodeSame(404);

            $client->loginUser($captain);
            $client->request('POST', '/account/competition-rosters/'.$competition->getId().'/participants/'.$participant->getId().'/members/'.$captain->getId().'/remove', [
                '_token' => $this->csrfToken($client, 'competition-roster-remove-'.$participant->getId().'-'.$captain->getId()),
            ]);
            self::assertResponseRedirects('/account/competition-rosters');
            $crawler = $client->followRedirect();
            self::assertSelectorTextContains('body', 'Die Teamleitung kann sich nicht selbst aus dem Team entfernen.');

            $client->request('POST', '/account/competition-rosters/'.$otherCompetition->getId().'/participants/'.$participant->getId().'/members/'.$member->getId().'/remove', [
                '_token' => $this->csrfToken($client, 'competition-roster-remove-'.$participant->getId().'-'.$member->getId()),
            ]);
            self::assertResponseStatusCodeSame(404);

            $entityManager = $this->entityManager($client);
            $savedParticipant = $entityManager->find(CompetitionParticipant::class, $participant->getId());
            self::assertInstanceOf(CompetitionParticipant::class, $savedParticipant);
            $savedParticipant->setRosterUserIds([$captain->getId(), $member->getId()]);
            $savedParticipant->checkIn();
            $entityManager->flush();

            $closedAction = '/account/competition-rosters/'.$competition->getId().'/participants/'.$participant->getId().'/members/'.$member->getId().'/remove';
            $client->request('POST', $closedAction, [
                '_token' => $this->csrfToken($client, 'competition-roster-remove-'.$participant->getId().'-'.$member->getId()),
            ]);
            self::assertResponseRedirects('/account/competition-rosters');
            $crawler = $client->followRedirect();
            self::assertSelectorTextContains('body', 'Mitglieder können nur vor dem Check-in');
            $entityManager->clear();
            $savedParticipant = $entityManager->find(CompetitionParticipant::class, $participant->getId());
            self::assertInstanceOf(CompetitionParticipant::class, $savedParticipant);
            self::assertContains($member->getId(), $savedParticipant->getRosterUserIds());
        } finally {
            try {
                $this->removeFixtures($this->entityManager($client), $fixtures);
            } finally {
                $this->restoreModuleStates($client, $moduleSnapshot);
            }
        }
    }

    public function testUnverifiedEmailInvalidCsrfAndUnavailableCompetitionPathsFailClosed(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->captureModuleStates($client);
        $this->setModulesEnabled($client, true);
        $entityManager = $this->entityManager($client);
        $fixtures = [];

        try {
            $suffix = bin2hex(random_bytes(5));
            $captain = $this->user($entityManager, 'reject-captain-'.$suffix);
            $unverified = $this->user($entityManager, 'unverified-'.$suffix, false);
            $otherCaptain = $this->user($entityManager, 'reject-other-'.$suffix);
            array_push($fixtures, $captain, $unverified, $otherCaptain);
            $game = $this->game($entityManager, $suffix);
            $disabledGame = $this->game($entityManager, $suffix.'-disabled');
            array_push($fixtures, $game, $disabledGame);
            $team = $this->competition($entityManager, $game, $suffix, Competition::MODE_TEAM, 3);
            $privateTeam = $this->competition($entityManager, $game, $suffix.'-private', Competition::MODE_TEAM, 3, Competition::VISIBILITY_PRIVATE);
            $solo = $this->competition($entityManager, $game, $suffix.'-solo', Competition::MODE_SOLO, 1);
            $closed = $this->competition($entityManager, $game, $suffix.'-closed', Competition::MODE_TEAM, 3);
            $closed->archive();
            $disabled = $this->competition($entityManager, $disabledGame, $suffix.'-disabled', Competition::MODE_TEAM, 3);
            $disabledGame->setEnabled(false);
            array_push($fixtures, $team, $privateTeam, $solo, $closed, $disabled);
            $entityManager->flush();
            $teamEntry = $this->participant($entityManager, $team, $captain, 'Open team '.$suffix, [$captain]);
            $privateEntry = $this->participant($entityManager, $privateTeam, $captain, 'Private team '.$suffix, [$captain]);
            $soloEntry = $this->participant($entityManager, $solo, $captain, 'Solo entry '.$suffix, [$captain]);
            $closedEntry = $this->participant($entityManager, $closed, $captain, 'Closed team '.$suffix, [$captain]);
            $disabledEntry = $this->participant($entityManager, $disabled, $captain, 'Disabled-game team '.$suffix, [$captain]);
            array_push($fixtures, $teamEntry, $privateEntry, $soloEntry, $closedEntry, $disabledEntry);
            $entityManager->flush();

            $client->loginUser($captain);
            $inviteAction = '/account/competition-rosters/'.$team->getId().'/participants/'.$teamEntry->getId().'/invite';
            $client->request('GET', '/account/competition-rosters');
            self::assertResponseIsSuccessful();
            $validToken = $this->csrfToken($client, 'competition-roster-invite-'.$teamEntry->getId());
            $client->request('POST', $inviteAction, ['_token' => 'invalid', 'email' => $unverified->getEmail()]);
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', $inviteAction, ['_token' => $validToken, 'email' => $unverified->getEmail()]);
            self::assertResponseRedirects('/account/competition-rosters');
            $crawler = $client->followRedirect();
            self::assertSelectorTextContains('body', 'bestätigte E-Mail-Adresse');
            self::assertSelectorNotExists('[data-invitation-link]');

            $entityManager = $this->entityManager($client);
            $verifiedTeammate = $this->user($entityManager, 'verified-'.$suffix);
            $fixtures[] = $verifiedTeammate;
            $entityManager->flush();
            $validPath = $this->createInvite($client, $team, $teamEntry, $verifiedTeammate->getEmail());
            $expiredPath = $this->expiredInvitePath($client, $team, $teamEntry, $captain, $verifiedTeammate);
            $client->loginUser($verifiedTeammate);
            $client->request('GET', $expiredPath);
            self::assertResponseStatusCodeSame(404);

            $lastSignatureCharacter = substr($validPath, -1);
            $forgedPath = substr($validPath, 0, -1).($lastSignatureCharacter === '0' ? '1' : '0');
            $client->request('GET', $forgedPath);
            self::assertResponseStatusCodeSame(404);

            $wrongCompetitionPath = str_replace('/competitions/'.$team->getId().'/', '/competitions/'.$privateTeam->getId().'/', $validPath);
            self::assertNotSame($validPath, $wrongCompetitionPath);
            $client->request('GET', $wrongCompetitionPath);
            self::assertResponseStatusCodeSame(404);
            $client->loginUser($captain);

            foreach ([
                [$privateTeam, $privateEntry],
                [$solo, $soloEntry],
                [$disabled, $disabledEntry],
            ] as [$competition, $participant]) {
                $action = '/account/competition-rosters/'.$competition->getId().'/participants/'.$participant->getId().'/invite';
                $client->request('POST', $action, [
                    '_token' => $this->csrfToken($client, 'competition-roster-invite-'.$participant->getId()),
                    'email' => $unverified->getEmail(),
                ]);
                self::assertResponseStatusCodeSame(404);
            }

            $closedAction = '/account/competition-rosters/'.$closed->getId().'/participants/'.$closedEntry->getId().'/invite';
            $client->request('POST', $closedAction, [
                '_token' => $this->csrfToken($client, 'competition-roster-invite-'.$closedEntry->getId()),
                'email' => $unverified->getEmail(),
            ]);
            self::assertResponseRedirects('/account/competition-rosters');
            $crawler = $client->followRedirect();
            self::assertSelectorTextContains('body', 'Einladungen können nur vor dem Check-in');

            $disabledModuleToken = $this->csrfToken($client, 'competition-roster-invite-'.$teamEntry->getId());
            $this->setModuleEnabled($this->entityManager($client), 'gaming', false);
            $client->request('GET', '/account/competition-rosters');
            self::assertResponseStatusCodeSame(404);
            $client->request('POST', $inviteAction, [
                '_token' => $disabledModuleToken,
                'email' => $captain->getEmail(),
            ]);
            self::assertResponseStatusCodeSame(404);
        } finally {
            try {
                $this->removeFixtures($this->entityManager($client), $fixtures);
            } finally {
                $this->restoreModuleStates($client, $moduleSnapshot);
            }
        }
    }

    private function createInvite(KernelBrowser $client, Competition $competition, CompetitionParticipant $participant, string $email): string
    {
        $crawler = $client->request('GET', '/account/competition-rosters');
        self::assertResponseIsSuccessful();
        $action = '/account/competition-rosters/'.$competition->getId().'/participants/'.$participant->getId().'/invite';
        $form = $crawler->filter('form[action="'.$action.'"]')->form();
        $form['email'] = $email;
        $client->submit($form);
        self::assertResponseRedirects('/account/competition-rosters');
        $crawler = $client->followRedirect();
        $link = $crawler->filter('[data-invitation-link]')->attr('href');
        if (!is_string($link)) {
            throw new \LogicException('The captain dashboard did not render the invitation link.');
        }
        $path = parse_url($link, PHP_URL_PATH);
        if (!is_string($path)) {
            throw new \LogicException('The invitation link has no local path.');
        }

        return $path;
    }

    private function expiredInvitePath(
        KernelBrowser $client,
        Competition $competition,
        CompetitionParticipant $participant,
        User $captain,
        User $invitee,
    ): string {
        $competitionId = $competition->getId();
        $participantId = $participant->getId();
        $captainId = $captain->getId();
        if ($competitionId === null || $participantId === null || $captainId === null) {
            throw new \LogicException('The expired invitation needs persisted competition identities.');
        }

        /** @var CompetitionRosterInviteLink $inviteLinks */
        $inviteLinks = $client->getContainer()->get(CompetitionRosterInviteLink::class);
        $reflection = new \ReflectionClass($inviteLinks);
        $emailFingerprintMethod = $reflection->getMethod('emailFingerprint');
        $rosterFingerprintMethod = $reflection->getMethod('rosterFingerprint');
        $emailFingerprint = $emailFingerprintMethod->invoke($inviteLinks, $invitee->getEmail());
        $rosterFingerprint = $rosterFingerprintMethod->invoke($inviteLinks, $competition, $participant);
        if (!is_string($emailFingerprint) || !is_string($rosterFingerprint)) {
            throw new \LogicException('The invitation fingerprints are unavailable.');
        }

        $issuedAt = time() - CompetitionRosterInviteLink::TTL_SECONDS - 10;
        $nonce = bin2hex(random_bytes(24));
        $claims = [
            'v' => 1,
            'competition' => $competitionId,
            'participant' => $participantId,
            'captain' => $captainId,
            'email' => $emailFingerprint,
            'roster' => $rosterFingerprint,
            'issuedAt' => $issuedAt,
            'expiresAt' => $issuedAt + CompetitionRosterInviteLink::TTL_SECONDS,
            'nonce' => $nonce,
        ];
        $encodedPayload = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $secret = $client->getContainer()->getParameter('kernel.secret');
        if (!is_string($secret)) {
            throw new \LogicException('The kernel secret is unavailable.');
        }
        $token = $encodedPayload.'.'.hash_hmac('sha256', $encodedPayload, $secret);

        $cache = $client->getContainer()->get('cache.app');
        if (!$cache instanceof CacheItemPoolInterface) {
            throw new \LogicException('The application cache is unavailable.');
        }
        $item = $cache->getItem('competition_roster_invite_'.hash('sha256', $nonce));
        $item->set(hash('sha256', $token));
        $item->expiresAfter(CompetitionRosterInviteLink::TTL_SECONDS);
        if (!$cache->save($item)) {
            throw new \LogicException('The expired invitation fixture could not be stored.');
        }

        return '/competitions/'.$competitionId.'/participants/'.$participantId.'/roster/join/'.$token;
    }

    private function user(EntityManagerInterface $entityManager, string $suffix, bool $verified = true): User
    {
        $user = (new User())
            ->setEmail('roster-'.$suffix.'@example.test')
            ->setDisplayName('Roster '.$suffix)
            ->setPassword('unused-test-hash');
        if ($verified) {
            $user->verifyEmail();
        }
        $entityManager->persist($user);

        return $user;
    }

    private function game(EntityManagerInterface $entityManager, string $suffix): Game
    {
        $game = (new Game())->setName('Game '.$suffix)->setSlug('game-'.$suffix);
        $entityManager->persist($game);

        return $game;
    }

    private function competition(
        EntityManagerInterface $entityManager,
        Game $game,
        string $slug,
        string $mode,
        int $teamSize,
        string $visibility = Competition::VISIBILITY_PUBLIC,
    ): Competition {
        $competition = (new Competition())
            ->setGame($game)
            ->setName('Competition '.$slug)
            ->setSlug('competition-'.$slug)
            ->setMode($mode)
            ->setTeamSize($teamSize)
            ->setVisibility($visibility);
        $competition->open();
        $entityManager->persist($competition);

        return $competition;
    }

    /** @param list<User> $members */
    private function participant(EntityManagerInterface $entityManager, Competition $competition, User $captain, string $name, array $members): CompetitionParticipant
    {
        $participant = (new CompetitionParticipant())
            ->setCompetition($competition)
            ->setCaptain($captain)
            ->setName($name);
        $ids = [];
        foreach ($members as $member) {
            if ($member->getId() !== null) {
                $ids[] = $member->getId();
            }
        }
        $participant->setRosterUserIds($ids);
        $entityManager->persist($participant);

        return $participant;
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

    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $container = $client->getContainer();
        $manager = $container->get('security.csrf.token_manager');
        if (!$manager instanceof CsrfTokenManagerInterface) {
            throw new \LogicException('The CSRF token manager is unavailable.');
        }

        $requestStack = $container->get(RequestStack::class);
        if (!$requestStack instanceof RequestStack) {
            throw new \LogicException('The request stack is unavailable.');
        }
        $session = $client->getSession();
        if (!$session instanceof SessionInterface) {
            throw new \LogicException('The browser session is unavailable.');
        }

        $request = Request::create('http://localhost/');
        $request->setSession($session);
        $requestStack->push($request);
        try {
            $token = $manager->getToken($tokenId)->getValue();
            $session->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    private function assertPrivateResponse(KernelBrowser $client): void
    {
        $directives = array_map('trim', explode(',', strtolower((string) $client->getResponse()->headers->get('Cache-Control'))));
        self::assertContains('private', $directives);
        self::assertContains('no-store', $directives);
        self::assertContains('max-age=0', $directives);
    }
}
