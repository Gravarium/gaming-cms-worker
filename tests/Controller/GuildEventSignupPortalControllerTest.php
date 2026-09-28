<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildEvent;
use App\Entity\GuildEventSignup;
use App\Entity\GuildMember;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class GuildEventSignupPortalControllerTest extends WebTestCase
{
    private const INDEX_PATH = '/guild-area/event-signups';
    private const MODULE_KEYS = ['content', 'gaming'];

    public function testDashboardShowsOnlyOwnActiveFutureSignupsAndIsPrivate(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->moduleSnapshot($client);
        $this->enableGaming($client);

        $em = $this->em($client);
        $fixtures = [];
        try {
            $suffix = bin2hex(random_bytes(5));
            $user = $this->user($em, $suffix);
            $otherUser = $this->user($em, $suffix.'-other');
            $game = $this->game($em, $suffix);
            $guild = $this->guild($em, $game, $suffix);
            $disabledGuild = $this->guild($em, $game, $suffix.'-disabled')->setEnabled(false);
            $owned = (new GuildMember())->setGuild($guild)->setUser($user)->setCharacterName('Owned '.$suffix);
            $other = (new GuildMember())->setGuild($guild)->setUser($otherUser)->setCharacterName('Other '.$suffix);
            $inactive = (new GuildMember())->setGuild($guild)->setUser($user)->setCharacterName('Inactive '.$suffix)->setActive(false);
            $disabledMember = (new GuildMember())->setGuild($disabledGuild)->setUser($user)->setCharacterName('Disabled guild '.$suffix);
            $visibleEvent = $this->event($guild, 'Visible signup '.$suffix);
            $otherEvent = $this->event($guild, 'Foreign signup '.$suffix);
            $inactiveEvent = $this->event($guild, 'Inactive signup '.$suffix);
            $disabledEvent = $this->event($disabledGuild, 'Disabled guild signup '.$suffix);
            $cancelledEvent = $this->event($guild, 'Cancelled signup '.$suffix)->setStatus(GuildEvent::STATUS_CANCELLED);
            $pastEvent = $this->event($guild, 'Past signup '.$suffix)->setStartsAt(new \DateTimeImmutable('-1 day'));
            $signups = [
                (new GuildEventSignup())->setEvent($visibleEvent)->setMember($owned)->setUser($user),
                (new GuildEventSignup())->setEvent($otherEvent)->setMember($other)->setUser($otherUser),
                (new GuildEventSignup())->setEvent($inactiveEvent)->setMember($inactive)->setUser($user),
                (new GuildEventSignup())->setEvent($disabledEvent)->setMember($disabledMember)->setUser($user),
                (new GuildEventSignup())->setEvent($cancelledEvent)->setMember($owned)->setUser($user),
                (new GuildEventSignup())->setEvent($pastEvent)->setMember($owned)->setUser($user),
            ];
            $fixtures = [$user, $otherUser, $game, $guild, $disabledGuild, $owned, $other, $inactive, $disabledMember,
                $visibleEvent, $otherEvent, $inactiveEvent, $disabledEvent, $cancelledEvent, $pastEvent, ...$signups];
            foreach ($fixtures as $fixture) {
                $em->persist($fixture);
            }
            $em->flush();
            $client->loginUser($user);

            $client->request('GET', self::INDEX_PATH);

            self::assertResponseIsSuccessful();
            $this->assertPrivateHeaders($client);
            self::assertSelectorTextContains('body', 'Visible signup '.$suffix);
            self::assertSelectorTextContains('body', 'Owned '.$suffix);
            self::assertStringNotContainsString('Foreign signup '.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('Inactive signup '.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('Disabled guild signup '.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('Cancelled signup '.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('Past signup '.$suffix, (string) $client->getResponse()->getContent());
        } finally {
            $this->cleanupFixtures($em, $fixtures);
            $this->restoreModuleSnapshot($client, $moduleSnapshot);
        }
    }

    public function testOwnerCanWithdrawOwnFutureSignupAndPreserveAttendanceEvidence(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->moduleSnapshot($client);
        $this->enableGaming($client);

        $em = $this->em($client);
        $fixtures = [];
        try {
            $suffix = bin2hex(random_bytes(5));
            $user = $this->user($em, $suffix);
            $otherUser = $this->user($em, $suffix.'-other');
            $game = $this->game($em, $suffix);
            $guild = $this->guild($em, $game, $suffix);
            $owned = (new GuildMember())->setGuild($guild)->setUser($user)->setCharacterName('Owner '.$suffix);
            $other = (new GuildMember())->setGuild($guild)->setUser($otherUser)->setCharacterName('Other '.$suffix);
            $event = $this->event($guild, 'Withdrawable event '.$suffix)->setMaxParticipants(1);
            $ownSignup = (new GuildEventSignup())
                ->setEvent($event)
                ->setMember($owned)
                ->setUser($user)
                ->setResponse(GuildEventSignup::GOING)
                ->setRole('damage')
                ->setNote('Please keep this private until withdrawn.')
                ->markAttendance(GuildEventSignup::ATTENDANCE_EXCUSED, $user);
            $otherSignup = (new GuildEventSignup())
                ->setEvent($event)
                ->setMember($other)
                ->setUser($otherUser)
                ->setResponse(GuildEventSignup::WAITLIST);
            $fixtures = [$user, $otherUser, $game, $guild, $owned, $other, $event, $ownSignup, $otherSignup];
            foreach ($fixtures as $fixture) {
                $em->persist($fixture);
            }
            $em->flush();
            $signupId = $this->requiredId($ownSignup->getId());
            $attendanceAt = $ownSignup->getAttendanceCheckedAt();
            $client->loginUser($user);

            $crawler = $client->request('GET', self::INDEX_PATH);
            $token = $crawler->filter(sprintf('form[action="%s/%d/withdraw"] input[name="_token"]', self::INDEX_PATH, $signupId))->attr('value');
            $client->request('POST', self::INDEX_PATH.'/'.$signupId.'/withdraw', ['_token' => $token]);

            self::assertResponseRedirects(self::INDEX_PATH);
            $this->assertPrivateHeaders($client);
            $em->clear();
            $storedSignup = $em->find(GuildEventSignup::class, $signupId);
            self::assertInstanceOf(GuildEventSignup::class, $storedSignup);
            self::assertSame(GuildEventSignup::DECLINED, $storedSignup->getResponse());
            self::assertNull($storedSignup->getNote());
            self::assertSame(GuildEventSignup::ATTENDANCE_EXCUSED, $storedSignup->getAttendance());
            self::assertSame($user->getId(), $storedSignup->getAttendanceCheckedBy()?->getId());
            self::assertSame($attendanceAt?->getTimestamp(), $storedSignup->getAttendanceCheckedAt()?->getTimestamp());
            self::assertSame(0, $em->getRepository(GuildEventSignup::class)->count(['event' => $event->getId(), 'response' => GuildEventSignup::GOING]));
            self::assertSame(GuildEventSignup::WAITLIST, $em->find(GuildEventSignup::class, $this->requiredId($otherSignup->getId()))?->getResponse());

            $repeatToken = $token;
            $client->request('POST', self::INDEX_PATH.'/'.$signupId.'/withdraw', ['_token' => $repeatToken]);
            self::assertResponseStatusCodeSame(404);
            self::assertSame(GuildEventSignup::DECLINED, $em->find(GuildEventSignup::class, $signupId)?->getResponse());
        } finally {
            $this->cleanupFixtures($em, $fixtures);
            $this->restoreModuleSnapshot($client, $moduleSnapshot);
        }
    }

    public function testInvalidCsrfAndForeignSignupCannotMutateAnotherUsersRegistration(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->moduleSnapshot($client);
        $this->enableGaming($client);

        $em = $this->em($client);
        $fixtures = [];
        try {
            $suffix = bin2hex(random_bytes(5));
            $user = $this->user($em, $suffix);
            $otherUser = $this->user($em, $suffix.'-other');
            $game = $this->game($em, $suffix);
            $guild = $this->guild($em, $game, $suffix);
            $otherMember = (new GuildMember())->setGuild($guild)->setUser($otherUser)->setCharacterName('Foreign '.$suffix);
            $event = $this->event($guild, 'Foreign target '.$suffix);
            $signup = (new GuildEventSignup())->setEvent($event)->setMember($otherMember)->setUser($otherUser)->setNote('Do not change');
            $fixtures = [$user, $otherUser, $game, $guild, $otherMember, $event, $signup];
            foreach ($fixtures as $fixture) {
                $em->persist($fixture);
            }
            $em->flush();
            $signupId = $this->requiredId($signup->getId());
            $client->loginUser($otherUser);
            $crawler = $client->request('GET', self::INDEX_PATH);
            $validToken = $crawler->filter(sprintf('form[action="%s/%d/withdraw"] input[name="_token"]', self::INDEX_PATH, $signupId))->attr('value');

            $client->loginUser($user);
            $client->request('GET', self::INDEX_PATH);
            $request = $client->getRequest();
            self::assertInstanceOf(Request::class, $request);
            $requestStack = $client->getContainer()->get(RequestStack::class);
            $requestStack->push($request);
            try {
                $tokenManager = $client->getContainer()->get(CsrfTokenManagerInterface::class);
                $validToken = $tokenManager->getToken('guild-event-signup-withdraw-'.$signupId)->getValue();
                $request->getSession()->save();
            } finally {
                $requestStack->pop();
            }

            $client->request('POST', self::INDEX_PATH.'/'.$signupId.'/withdraw', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', self::INDEX_PATH.'/'.$signupId.'/withdraw', ['_token' => $validToken]);
            self::assertResponseStatusCodeSame(404);
            self::assertSame(GuildEventSignup::GOING, $em->find(GuildEventSignup::class, $signupId)?->getResponse());
            self::assertSame('Do not change', $em->find(GuildEventSignup::class, $signupId)?->getNote());
        } finally {
            $this->cleanupFixtures($em, $fixtures);
            $this->restoreModuleSnapshot($client, $moduleSnapshot);
        }
    }

    public function testPaginationIsStableBoundedAndRejectsMalformedPages(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->moduleSnapshot($client);
        $this->enableGaming($client);

        $em = $this->em($client);
        $fixtures = [];
        try {
            $suffix = bin2hex(random_bytes(5));
            $user = $this->user($em, $suffix);
            $game = $this->game($em, $suffix);
            $guild = $this->guild($em, $game, $suffix);
            $fixtures = [$user, $game, $guild];
            $startsAt = new \DateTimeImmutable('+2 days');
            for ($i = 0; $i < 27; ++$i) {
                $member = (new GuildMember())->setGuild($guild)->setUser($user)->setCharacterName(sprintf('Character %02d %s', $i, $suffix));
                $event = (new GuildEvent())->setGuild($guild)->setTitle(sprintf('Event %02d %s', $i, $suffix))->setStartsAt($startsAt);
                $signup = (new GuildEventSignup())->setEvent($event)->setMember($member)->setUser($user);
                foreach ([$member, $event, $signup] as $fixture) {
                    $em->persist($fixture);
                    $fixtures[] = $fixture;
                }
            }
            $em->flush();
            $client->loginUser($user);

            $firstPage = $client->request('GET', self::INDEX_PATH);
            self::assertResponseIsSuccessful();
            self::assertCount(25, $firstPage->filter(sprintf('form[action^="%s/"]', self::INDEX_PATH)));
            self::assertSelectorTextContains('body', 'Event 00 '.$suffix);
            self::assertSelectorTextContains('body', 'Event 24 '.$suffix);
            self::assertStringNotContainsString('Event 25 '.$suffix, (string) $client->getResponse()->getContent());

            $secondPage = $client->request('GET', self::INDEX_PATH.'?page=2');
            self::assertResponseIsSuccessful();
            self::assertCount(2, $secondPage->filter(sprintf('form[action^="%s/"]', self::INDEX_PATH)));
            self::assertSelectorTextContains('body', 'Event 25 '.$suffix);
            self::assertSelectorTextContains('body', 'Event 26 '.$suffix);

            $client->request('GET', self::INDEX_PATH.'?page=999');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Event 26 '.$suffix);

            $client->request('GET', self::INDEX_PATH.'?page=1.5');
            self::assertResponseStatusCodeSame(400);

            $client->request('GET', self::INDEX_PATH.'?page%5B%5D=1');
            self::assertResponseStatusCodeSame(400);
        } finally {
            $this->cleanupFixtures($em, $fixtures);
            $this->restoreModuleSnapshot($client, $moduleSnapshot);
        }
    }

    public function testDisabledGamingHidesTheDashboard(): void
    {
        $client = static::createClient();
        $moduleSnapshot = $this->moduleSnapshot($client);
        $this->enableGaming($client);
        $em = $this->em($client);
        $fixtures = [];
        try {
            $suffix = bin2hex(random_bytes(5));
            $user = $this->user($em, $suffix);
            $game = $this->game($em, $suffix);
            $guild = $this->guild($em, $game, $suffix);
            $member = (new GuildMember())->setGuild($guild)->setUser($user)->setCharacterName('Member '.$suffix);
            $event = $this->event($guild, 'Hidden event '.$suffix);
            $signup = (new GuildEventSignup())->setEvent($event)->setMember($member)->setUser($user);
            $fixtures = [$user, $game, $guild, $member, $event, $signup];
            foreach ($fixtures as $fixture) {
                $em->persist($fixture);
            }
            $em->flush();
            $client->loginUser($user);
            $this->setModuleState($client, 'gaming', false);

            $client->request('GET', self::INDEX_PATH);
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('Hidden event '.$suffix, (string) $client->getResponse()->getContent());
        } finally {
            $this->cleanupFixtures($em, $fixtures);
            $this->restoreModuleSnapshot($client, $moduleSnapshot);
        }
    }

    /** @return array{content: bool|null, gaming: bool|null} */
    private function moduleSnapshot(KernelBrowser $client): array
    {
        $em = $this->em($client);
        $snapshot = ['content' => null, 'gaming' => null];
        foreach (self::MODULE_KEYS as $key) {
            $state = $em->find(CmsModuleState::class, $key);
            $snapshot[$key] = $state?->isEnabled();
        }

        return $snapshot;
    }

    private function enableGaming(KernelBrowser $client): void
    {
        $this->setModuleState($client, 'content', true);
        $this->setModuleState($client, 'gaming', true);
    }

    private function setModuleState(KernelBrowser $client, string $key, bool $enabled): void
    {
        $em = $this->em($client);
        $state = $em->find(CmsModuleState::class, $key);
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey($key)->updateVersion('1.0.0');
            $em->persist($state);
        }
        $state->setEnabled($enabled);
        $em->flush();
    }

    /** @param array{content: bool|null, gaming: bool|null} $snapshot */
    private function restoreModuleSnapshot(KernelBrowser $client, array $snapshot): void
    {
        $em = $this->em($client);
        $em->clear();
        foreach ($snapshot as $key => $previous) {
            $state = $em->find(CmsModuleState::class, $key);
            if ($previous === null) {
                if ($state instanceof CmsModuleState) {
                    $em->remove($state);
                }
            } elseif ($state instanceof CmsModuleState) {
                $state->setEnabled($previous);
            } else {
                $em->persist((new CmsModuleState())->setModuleKey($key)->updateVersion('1.0.0')->setEnabled($previous));
            }
        }
        $em->flush();
        $em->clear();
    }

    /** @param list<object> $fixtures */
    private function cleanupFixtures(EntityManagerInterface $em, array $fixtures): void
    {
        $em->clear();
        foreach (array_reverse($fixtures) as $fixture) {
            $id = $this->fixtureId($fixture);
            if ($id === null) {
                continue;
            }
            $managed = $em->find($fixture::class, $id);
            if (is_object($managed)) {
                $em->remove($managed);
            }
        }
        $em->flush();
        $em->clear();
    }

    private function fixtureId(object $fixture): ?int
    {
        if ($fixture instanceof GuildEventSignup || $fixture instanceof GuildEvent || $fixture instanceof GuildMember
            || $fixture instanceof Guild || $fixture instanceof Game || $fixture instanceof User) {
            return $fixture->getId();
        }

        return null;
    }

    private function user(EntityManagerInterface $em, string $suffix): User
    {
        $user = (new User())
            ->setEmail('event-signups-'.$suffix.'@example.test')
            ->setDisplayName('Event signup '.$suffix)
            ->verifyEmail();
        $em->persist($user);

        return $user;
    }

    private function game(EntityManagerInterface $em, string $suffix): Game
    {
        $game = (new Game())->setName('Signups game '.$suffix)->setSlug('signups-game-'.$suffix);
        $em->persist($game);

        return $game;
    }

    private function guild(EntityManagerInterface $em, Game $game, string $suffix): Guild
    {
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Signups guild '.$suffix)
            ->setSlug('signups-guild-'.$suffix)
            ->setServerName('EU')
            ->setDescription('Guild event signup test');
        $em->persist($guild);

        return $guild;
    }

    private function event(Guild $guild, string $title): GuildEvent
    {
        return (new GuildEvent())
            ->setGuild($guild)
            ->setTitle($title)
            ->setDescription('Upcoming guild event')
            ->setStartsAt(new \DateTimeImmutable('+2 days'));
    }

    private function assertPrivateHeaders(KernelBrowser $client): void
    {
        $cacheControl = strtolower((string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertMatchesRegularExpression('/(?:^|,\s*)private(?:,|$)/', $cacheControl);
        self::assertMatchesRegularExpression('/(?:^|,\s*)no-store(?:,|$)/', $cacheControl);
        self::assertSame('noindex, nofollow, noarchive', $client->getResponse()->headers->get('X-Robots-Tag'));
    }

    private function requiredId(?int $id): int
    {
        self::assertNotNull($id);

        return $id;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
