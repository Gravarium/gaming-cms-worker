<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildEvent;
use App\Entity\GuildTeam;
use App\Module\CmsModuleManager;
use App\Widget\PublicGuildEventsQuery;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class PublicGuildEventsWidgetProviderTest extends WebTestCase
{
    private const WIDGET_KEY = 'gaming.public-guild-events';
    private const MODULE_KEYS = ['content', 'gaming'];

    public function testPublicWidgetRendersOnlyUpcomingEnabledGuildWideEvents(): void
    {
        $client = static::createClient();
        $this->resetModuleStates($client);
        $this->enableGaming($client);

        $em = $this->em($client);
        $fixtures = [];
        try {
            $now = new \DateTimeImmutable();
            $suffix = bin2hex(random_bytes(5));
            $game = $this->game($em, $suffix);
            $fixtures[] = $game;
            $guild = $this->guild($em, $game, $suffix);
            $fixtures[] = $guild;
            $included = $this->event($guild, 'Visible <em>raid</em>', $now->modify('+1 hour'));
            $this->trackEvent($em, $fixtures, $included);
            $team = (new GuildTeam())->setGuild($guild)->setName('Private team '.$suffix);
            $em->persist($team);
            $fixtures[] = $team;
            $this->trackEvent($em, $fixtures, $this->event($guild, 'Private team event', $now->modify('+2 hours'))->setTeam($team));
            $this->trackEvent($em, $fixtures, $this->event($guild, 'Cancelled event', $now->modify('+3 hours'))->setStatus(GuildEvent::STATUS_CANCELLED));
            $this->trackEvent($em, $fixtures, $this->event($guild, 'Completed event', $now->modify('+4 hours'))->setStatus(GuildEvent::STATUS_DONE));
            $this->trackEvent($em, $fixtures, $this->event($guild, 'Stale event', $now->modify('-3 hours')));
    
            $disabledGuild = $this->guild($em, $game, $suffix.'-disabled-guild')->setEnabled(false);
            $fixtures[] = $disabledGuild;
            $this->trackEvent($em, $fixtures, $this->event($disabledGuild, 'Disabled guild event', $now->modify('+5 hours')));
            $disabledGame = $this->game($em, $suffix.'-disabled-game')->setEnabled(false);
            $fixtures[] = $disabledGame;
            $disabledGameGuild = $this->guild($em, $disabledGame, $suffix.'-disabled-game')->setEnabled(true);
            $fixtures[] = $disabledGameGuild;
            $this->trackEvent($em, $fixtures, $this->event($disabledGameGuild, 'Disabled game event', $now->modify('+6 hours')));
            $em->flush();
    
                $registry = $client->getContainer()->get(WidgetRegistry::class);
            $definition = $registry->get(self::WIDGET_KEY);
            self::assertNotNull($definition);
            self::assertSame('gaming', $definition->module);
            self::assertTrue($registry->available(self::WIDGET_KEY));

            $data = $registry->data(self::WIDGET_KEY, ['event_limit' => 6]);
            self::assertSame(['events'], array_keys($data));
            $renderedEvents = $data['events'] ?? null;
            self::assertIsArray($renderedEvents);
            self::assertSame(
                [$included->getId()],
                array_map(static fn (mixed $event): ?int => $event instanceof GuildEvent ? $event->getId() : null, $renderedEvents),
            );

            $html = $client->getContainer()->get(Environment::class)->render($definition->template, $data);
            self::assertStringContainsString('Visible &lt;em&gt;raid&lt;/em&gt;', $html);
            self::assertStringContainsString($guild->getSlug(), $html);
            self::assertStringNotContainsString('<em>raid</em>', $html);
            self::assertStringNotContainsString('Private team event', $html);
            self::assertStringNotContainsString('Disabled guild event', $html);
            self::assertStringNotContainsString('Disabled game event', $html);
            self::assertStringNotContainsString('Warteliste', $html);
            self::assertStringNotContainsString('Anmeldung', $html);
        } finally {
            $this->cleanupFixtures($em, $fixtures);
            $this->resetModuleStates($client);
        }
    }

    public function testQueryAndWidgetClampToTwelveWithStableOrder(): void
    {
        $client = static::createClient();
        $this->resetModuleStates($client);
        $this->enableGaming($client);

        $em = $this->em($client);
        $fixtures = [];

        try {
            $now = new \DateTimeImmutable();
            $suffix = bin2hex(random_bytes(5));
            $game = $this->game($em, $suffix);
            $fixtures[] = $game;
            $guild = $this->guild($em, $game, $suffix);
            $fixtures[] = $guild;
            $start = $now->modify('+1 day');
            for ($i = 0; $i < 15; ++$i) {
                $this->trackEvent($em, $fixtures, $this->event($guild, 'Event '.$i, $start));
            }
            $em->flush();

            $query = $client->getContainer()->get(PublicGuildEventsQuery::class);
            $events = $query->upcoming($now, 999);
            self::assertCount(12, $events);
            $ids = array_map(static fn (GuildEvent $event): ?int => $event->getId(), $events);
            $sortedIds = $ids;
            sort($sortedIds);
            self::assertSame($sortedIds, $ids);

            $data = $client->getContainer()->get(WidgetRegistry::class)->data(self::WIDGET_KEY, ['event_limit' => 999]);
            $widgetEvents = $data['events'] ?? null;
            self::assertIsArray($widgetEvents);
            self::assertCount(12, $widgetEvents);
        } finally {
            $this->cleanupFixtures($em, $fixtures);
            $this->resetModuleStates($client);
        }
    }

    public function testWidgetRendersAnEmptyStateWhenNoPublicEventIsAvailable(): void
    {
        $client = static::createClient();
        $this->resetModuleStates($client);
        $this->enableGaming($client);

        try {
            $registry = $client->getContainer()->get(WidgetRegistry::class);
            $definition = $registry->get(self::WIDGET_KEY);
            self::assertNotNull($definition);
            $data = $registry->data(self::WIDGET_KEY, ['event_limit' => 6]);

            $html = $client->getContainer()->get(Environment::class)->render($definition->template, $data);
            self::assertStringContainsString('Zurzeit sind keine öffentlichen Gildentermine geplant.', $html);
        } finally {
            $this->resetModuleStates($client);
        }
    }

    public function testDisabledGamingSuppressesWidgetAndItsData(): void
    {
        $client = static::createClient();
        $this->resetModuleStates($client);
        $this->moduleState($client, 'content', true);
        $this->moduleState($client, 'gaming', false);

        try {
            $registry = $client->getContainer()->get(WidgetRegistry::class);
            self::assertFalse($registry->available(self::WIDGET_KEY));
            self::assertSame([], $registry->data(self::WIDGET_KEY, ['event_limit' => 6]));
            self::assertNotContains(self::WIDGET_KEY, array_map(static fn (WidgetDefinition $definition): string => $definition->key, $registry->availableDefinitions()));
        } finally {
            $this->resetModuleStates($client);
        }
    }

    private function enableGaming(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $this->moduleState($client, 'content', true);
        $this->moduleState($client, 'gaming', true);
        self::assertTrue($client->getContainer()->get(CmsModuleManager::class)->isEnabled('gaming'));
    }

    private function moduleState(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $key, bool $enabled): void
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

    private function resetModuleStates(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $em = $this->em($client);
        foreach (self::MODULE_KEYS as $key) {
            $state = $em->find(CmsModuleState::class, $key);
            if ($state instanceof CmsModuleState) {
                $em->remove($state);
            }
        }
        $em->flush();
        $em->clear();
    }

    /** @param list<object> $fixtures */
    private function trackEvent(EntityManagerInterface $em, array &$fixtures, GuildEvent $event): void
    {
        $em->persist($event);
        $fixtures[] = $event;
    }

    /** @param list<object> $fixtures */
    private function cleanupFixtures(EntityManagerInterface $em, array $fixtures): void
    {
        foreach (array_reverse($fixtures) as $fixture) {
            $em->remove($fixture);
        }
        if ($fixtures !== []) {
            $em->flush();
        }
        $em->clear();
    }

    private function game(EntityManagerInterface $em, string $suffix): Game
    {
        $game = (new Game())->setName('Game '.$suffix)->setSlug('game-'.$suffix);
        $em->persist($game);

        return $game;
    }

    private function guild(EntityManagerInterface $em, Game $game, string $suffix): Guild
    {
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Guild '.$suffix)
            ->setSlug('guild-'.$suffix)
            ->setServerName('EU')
            ->setDescription('Public schedule fixture');
        $em->persist($guild);

        return $guild;
    }

    private function event(Guild $guild, string $title, \DateTimeImmutable $startsAt): GuildEvent
    {
        return (new GuildEvent())
            ->setGuild($guild)
            ->setTitle($title)
            ->setDescription('Public details')
            ->setStartsAt($startsAt);
    }

    private function em(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
