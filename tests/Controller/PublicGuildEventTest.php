<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicGuildEventTest extends WebTestCase
{
    public function testPublicGuildScheduleShowsOnlyUpcomingPlannedEvents(): void
    {
        $client = static::createClient();
        [$game, $guild, $hiddenGuild] = $this->guildFixtures($client);
        $suffix = bin2hex(random_bytes(5));
        $startsAt = new \DateTimeImmutable('+2 days');
        $visible = (new GuildEvent())
            ->setGuild($guild)
            ->setTitle('Public raid '.$suffix)
            ->setDescription('Visible public event '.$suffix)
            ->setStartsAt($startsAt)
            ->setEndsAt($startsAt->modify('+2 hours'))
            ->setLocation('Community server')
            ->setMaxParticipants(20)
            ->setStatus(GuildEvent::STATUS_PLANNED);
        $cancelled = (new GuildEvent())
            ->setGuild($guild)
            ->setTitle('Cancelled event '.$suffix)
            ->setDescription('Must stay hidden.')
            ->setStartsAt($startsAt->modify('+1 day'))
            ->setStatus(GuildEvent::STATUS_CANCELLED);
        $done = (new GuildEvent())
            ->setGuild($guild)
            ->setTitle('Completed event '.$suffix)
            ->setDescription('Must stay hidden.')
            ->setStartsAt($startsAt->modify('+2 days'))
            ->setStatus(GuildEvent::STATUS_DONE);
        $past = (new GuildEvent())
            ->setGuild($guild)
            ->setTitle('Past event '.$suffix)
            ->setDescription('Must stay hidden.')
            ->setStartsAt(new \DateTimeImmutable('-1 day'))
            ->setStatus(GuildEvent::STATUS_PLANNED);
        $foreign = (new GuildEvent())
            ->setGuild($hiddenGuild)
            ->setTitle('Foreign event '.$suffix)
            ->setDescription('Must stay hidden.')
            ->setStartsAt($startsAt)
            ->setStatus(GuildEvent::STATUS_PLANNED);
        $em = $this->em($client);
        foreach ([$visible, $cancelled, $done, $past, $foreign] as $event) {
            $em->persist($event);
        }
        $em->flush();
        $eventIds = array_map(static fn (GuildEvent $event): ?int => $event->getId(), [$visible, $cancelled, $done, $past, $foreign]);
        $guildIds = [$guild->getId(), $hiddenGuild->getId()];
        $gameId = $game->getId();

        try {
            $client->request('GET', '/gaming/guild/'.$guild->getSlug().'/events');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Anstehende Termine');
            self::assertSelectorTextContains('body', 'Public raid '.$suffix);
            self::assertSelectorTextContains('body', 'Visible public event '.$suffix);
            self::assertSelectorTextContains('body', 'Community server');
            self::assertSelectorTextContains('body', 'Teilnehmerlimit: 20');
            self::assertStringNotContainsString('Cancelled event '.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('Completed event '.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('Past event '.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('Foreign event '.$suffix, (string) $client->getResponse()->getContent());
            self::assertSelectorNotExists('form');

            $client->request('GET', '/gaming/guild/'.$hiddenGuild->getSlug().'/events');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/gaming/guild/missing-public-event-guild-'.$suffix.'/events');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->removeFixtures($client, $eventIds, $guildIds, [$gameId]);
        }
    }

    public function testPublicGuildWithoutUpcomingEventsShowsAnEmptyState(): void
    {
        $client = static::createClient();
        [$game, $guild] = $this->guildFixtures($client);
        $guildId = $guild->getId();
        $gameId = $game->getId();

        try {
            $client->request('GET', '/gaming/guild/'.$guild->getSlug().'/events');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Für diese Gilde sind derzeit keine anstehenden Termine veröffentlicht.');
        } finally {
            $this->removeFixtures($client, [], [$guildId], [$gameId]);
        }
    }

    public function testDisabledGamingModuleReturnsNotFoundWithoutEventContents(): void
    {
        $client = static::createClient();
        [$game, $guild] = $this->guildFixtures($client);
        $hiddenTitle = 'Hidden while gaming is disabled '.bin2hex(random_bytes(5));
        $event = (new GuildEvent())
            ->setGuild($guild)
            ->setTitle($hiddenTitle)
            ->setDescription('Must not be rendered.')
            ->setStartsAt(new \DateTimeImmutable('+2 days'))
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
            $state = (new CmsModuleState())
                ->setModuleKey('gaming')
                ->updateVersion('1.0.0');
            $em->persist($state);
        }
        $state->setEnabled(false);
        $em->flush();

        try {
            $client->request('GET', '/gaming/guild/'.$guild->getSlug().'/events');

            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString($hiddenTitle, (string) $client->getResponse()->getContent());
        } finally {
            $this->restoreGamingModule($client, $previousEnabled);
            $this->removeFixtures($client, [$eventId], [$guildId], [$gameId]);
        }
    }

    /** @return array{Game, Guild, Guild} */
    private function guildFixtures(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())
            ->setName('Public events game '.$suffix)
            ->setSlug('public-events-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Public events guild '.$suffix)
            ->setSlug('public-events-guild-'.$suffix)
            ->setServerName('Public server')
            ->setDescription('Public guild event test guild.');
        $hiddenGuild = (new Guild())
            ->setGame($game)
            ->setName('Hidden events guild '.$suffix)
            ->setSlug('hidden-events-guild-'.$suffix)
            ->setServerName('Hidden server')
            ->setDescription('Disabled guild event test guild.')
            ->setEnabled(false);
        $em = $this->em($client);
        $em->persist($game);
        $em->persist($guild);
        $em->persist($hiddenGuild);
        $em->flush();

        return [$game, $guild, $hiddenGuild];
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
