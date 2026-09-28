<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildTeam;
use App\Entity\GuildEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicGuildEventCalendarFeedTest extends WebTestCase
{
    public function testFeedContainsOnlyEligibleEventsAndLinksFromPublicSchedule(): void
    {
        $client = static::createClient();
        [$game, $guild, $disabledGuild, $disabledGame, $disabledGameGuild] = $this->guildFixtures($client);
        $suffix = bin2hex(random_bytes(5));
        $startsAt = new \DateTimeImmutable('+1 hour');
        $team = (new GuildTeam())->setGuild($guild)->setName('Private team '.$suffix);
        $visible = (new GuildEvent())
            ->setGuild($guild)
            ->setTitle('Public raid '.$suffix)
            ->setDescription('Private detail omitted from the feed.')
            ->setLocation('Private location omitted from the feed.')
            ->setStartsAt($startsAt)
            ->setEndsAt($startsAt->modify('+2 hours'))
            ->setStatus(GuildEvent::STATUS_PLANNED);
        $cancelled = (new GuildEvent())
            ->setGuild($guild)
            ->setTitle('Cancelled feed event '.$suffix)
            ->setStartsAt($startsAt->modify('+1 day'))
            ->setStatus(GuildEvent::STATUS_CANCELLED);
        $completed = (new GuildEvent())
            ->setGuild($guild)
            ->setTitle('Completed feed event '.$suffix)
            ->setStartsAt($startsAt->modify('+2 days'))
            ->setStatus(GuildEvent::STATUS_DONE);
        $past = (new GuildEvent())
            ->setGuild($guild)
            ->setTitle('Past feed event '.$suffix)
            ->setStartsAt(new \DateTimeImmutable('-1 day'))
            ->setStatus(GuildEvent::STATUS_PLANNED);
        $hiddenGuildEvent = (new GuildEvent())
            ->setGuild($disabledGuild)
            ->setTitle('Disabled guild event '.$suffix)
            ->setStartsAt($startsAt)
            ->setStatus(GuildEvent::STATUS_PLANNED);
        $disabledGameEvent = (new GuildEvent())
            ->setGuild($disabledGameGuild)
            ->setTitle('Disabled game event '.$suffix)
            ->setStartsAt($startsAt)
            ->setStatus(GuildEvent::STATUS_PLANNED);
        $teamScopedEvent = (new GuildEvent())
            ->setGuild($guild)
            ->setTeam($team)
            ->setTitle('Team-only event '.$suffix)
            ->setStartsAt($startsAt)
            ->setStatus(GuildEvent::STATUS_PLANNED);

        $em = $this->em($client);
        $em->persist($team);
        $events = [$visible, $cancelled, $completed, $past, $hiddenGuildEvent, $disabledGameEvent, $teamScopedEvent];
        foreach ($events as $event) {
            $em->persist($event);
        }
        $em->flush();

        $eventIds = array_map(static fn (GuildEvent $event): ?int => $event->getId(), $events);
        $guildIds = [$guild->getId(), $disabledGuild->getId(), $disabledGameGuild->getId()];
        $gameIds = [$game->getId(), $disabledGame->getId()];

        try {
            $client->request('GET', '/gaming/events/calendar.ics');

            self::assertResponseIsSuccessful();
            self::assertSame('text/calendar; charset=utf-8', $client->getResponse()->headers->get('Content-Type'));
            $cacheControl = strtolower((string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('public', $cacheControl);
            self::assertStringContainsString('max-age=300', $cacheControl);
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));

            $body = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('BEGIN:VCALENDAR', $body);
            self::assertStringContainsString('END:VCALENDAR', $body);
            self::assertStringContainsString('UID:guild-event-'.$visible->getId().'@gaming-cms', $body);
            self::assertStringContainsString('SUMMARY:Public raid '.$suffix, $body);
            self::assertStringNotContainsString('Private detail omitted', $body);
            self::assertStringNotContainsString('Private location omitted', $body);
            self::assertStringNotContainsString('Cancelled feed event '.$suffix, $body);
            self::assertStringNotContainsString('Completed feed event '.$suffix, $body);
            self::assertStringNotContainsString('Past feed event '.$suffix, $body);
            self::assertStringNotContainsString('Disabled guild event '.$suffix, $body);
            self::assertStringNotContainsString('Disabled game event '.$suffix, $body);
            self::assertStringNotContainsString('Team-only event '.$suffix, $body);

            $client->request('GET', '/gaming/guild/'.$guild->getSlug().'/events');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('a[href="/gaming/events/calendar.ics"]');
            self::assertSelectorTextContains('a[href="/gaming/events/calendar.ics"]', 'Kalender abonnieren');

            $client->request('POST', '/gaming/events/calendar.ics');
            self::assertResponseStatusCodeSame(405);
        } finally {
            $this->removeFixtures($client, $eventIds, $guildIds, $gameIds);
        }
    }

    public function testDisabledGamingModuleHidesTheFeed(): void
    {
        $client = static::createClient();
        [$game, $guild] = $this->guildFixtures($client);
        $suffix = bin2hex(random_bytes(5));
        $event = (new GuildEvent())
            ->setGuild($guild)
            ->setTitle('Hidden calendar event '.$suffix)
            ->setStartsAt(new \DateTimeImmutable('+1 hour'))
            ->setStatus(GuildEvent::STATUS_PLANNED);
        $this->em($client)->persist($event);
        $this->em($client)->flush();
        $eventId = $event->getId();
        $guildId = $guild->getId();
        $gameId = $game->getId();

        $em = $this->em($client);
        $state = $em->getRepository(CmsModuleState::class)->find('gaming');
        $previousEnabled = $state?->isEnabled();
        if ($state === null) {
            $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
            $em->persist($state);
        }
        $state->setEnabled(false);
        $em->flush();

        try {
            $client->request('GET', '/gaming/events/calendar.ics');

            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('Hidden calendar event '.$suffix, (string) $client->getResponse()->getContent());
        } finally {
            $this->restoreGamingModule($client, $previousEnabled);
            $this->removeFixtures($client, [$eventId], [$guildId], [$gameId]);
        }
    }

    /** @return array{Game, Guild, Guild, Game, Guild} */
    private function guildFixtures(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())->setName('Calendar game '.$suffix)->setSlug('calendar-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Calendar guild '.$suffix)
            ->setSlug('calendar-guild-'.$suffix)
            ->setServerName('Calendar server')
            ->setDescription('Public calendar feed fixture.');
        $disabledGuild = (new Guild())
            ->setGame($game)
            ->setName('Disabled guild '.$suffix)
            ->setSlug('disabled-calendar-guild-'.$suffix)
            ->setServerName('Hidden calendar server')
            ->setDescription('Disabled guild fixture.')
            ->setEnabled(false);
        $disabledGame = (new Game())
            ->setName('Disabled calendar game '.$suffix)
            ->setSlug('disabled-calendar-game-'.$suffix)
            ->setEnabled(false);
        $disabledGameGuild = (new Guild())
            ->setGame($disabledGame)
            ->setName('Disabled game guild '.$suffix)
            ->setSlug('disabled-game-calendar-guild-'.$suffix)
            ->setServerName('Hidden calendar server')
            ->setDescription('Disabled game fixture.');

        $em = $this->em($client);
        foreach ([$game, $disabledGame, $guild, $disabledGuild, $disabledGameGuild] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [$game, $guild, $disabledGuild, $disabledGame, $disabledGameGuild];
    }

    /** @param list<int|null> $eventIds @param list<int|null> $guildIds @param list<int|null> $gameIds */
    private function removeFixtures(KernelBrowser $client, array $eventIds, array $guildIds, array $gameIds): void
    {
        $em = $this->em($client);
        $em->clear();
        foreach (array_filter($eventIds, static fn (?int $id): bool => $id !== null) as $id) {
            $event = $em->find(GuildEvent::class, $id);
            if ($event !== null) {
                $em->remove($event);
            }
        }
        $em->flush();
        $em->clear();
        foreach (array_reverse(array_filter($guildIds, static fn (?int $id): bool => $id !== null)) as $id) {
            $guild = $em->find(Guild::class, $id);
            if ($guild !== null) {
                $em->remove($guild);
            }
        }
        $em->flush();
        $em->clear();
        foreach (array_filter($gameIds, static fn (?int $id): bool => $id !== null) as $id) {
            $game = $em->find(Game::class, $id);
            if ($game !== null) {
                $em->remove($game);
            }
        }
        $em->flush();
        $em->clear();
    }

    private function restoreGamingModule(KernelBrowser $client, ?bool $previousEnabled): void
    {
        $em = $this->em($client);
        $em->clear();
        $state = $em->getRepository(CmsModuleState::class)->find('gaming');
        if ($previousEnabled === null) {
            if ($state !== null) {
                $em->remove($state);
            }
        } elseif ($state !== null) {
            $state->setEnabled($previousEnabled);
        }
        $em->flush();
        $em->clear();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
