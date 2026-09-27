<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildEvent;
use App\Entity\GuildEventSignup;
use App\Entity\GuildMember;
use App\Entity\GuildTeam;
use App\Entity\PageLayout;
use App\Entity\User;
use App\Layout\LayoutValidator;
use App\Theme\ThemeRegistry;
use App\Widget\GuildEvent\UpcomingGuildEventsQuery;
use App\Widget\UpcomingGuildEventsWidgetProvider;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UpcomingGuildEventsWidgetProviderTest extends WebTestCase
{
    public function testPageBuilderWidgetShowsOnlyUpcomingEventsForTheMemberAndKeepsThePagePrivate(): void
    {
        $client = static::createClient();
        $ids = $this->newIds();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));

        try {
            $user = $this->newUser($suffix.'-member');
            $otherUser = $this->newUser($suffix.'-other');
            $unaffiliatedUser = $this->newUser($suffix.'-unaffiliated');
            $game = $this->newGame($suffix, true);
            $disabledGame = $this->newGame($suffix.'-disabled', false);
            $guild = $this->newGuild($game, $suffix.'-active', true);
            $disabledGuild = $this->newGuild($game, $suffix.'-guild-off', false);
            $disabledGameGuild = $this->newGuild($disabledGame, $suffix.'-game-off', true);
            $inactiveMemberGuild = $this->newGuild($game, $suffix.'-inactive-member', true);
            $member = $this->newMember($guild, $user, 'Widget Character');
            $secondCharacter = $this->newMember($guild, $user, 'Second Widget Character');
            $otherMember = $this->newMember($guild, $otherUser, 'Other Character');
            $inactiveMember = $this->newMember($inactiveMemberGuild, $user, 'Inactive Character', false);

            foreach ([$user, $otherUser, $unaffiliatedUser, $game, $disabledGame, $guild, $disabledGuild, $disabledGameGuild, $inactiveMemberGuild, $member, $secondCharacter, $otherMember, $inactiveMember] as $entity) {
                $em->persist($entity);
            }
            $em->flush();
            $this->remember($ids, 'users', $user->getId());
            $this->remember($ids, 'users', $otherUser->getId());
            $this->remember($ids, 'users', $unaffiliatedUser->getId());
            $this->remember($ids, 'games', $game->getId());
            $this->remember($ids, 'games', $disabledGame->getId());
            $this->remember($ids, 'guilds', $guild->getId());
            $this->remember($ids, 'guilds', $disabledGuild->getId());
            $this->remember($ids, 'guilds', $disabledGameGuild->getId());
            $this->remember($ids, 'guilds', $inactiveMemberGuild->getId());
            $this->remember($ids, 'members', $member->getId());
            $this->remember($ids, 'members', $secondCharacter->getId());
            $this->remember($ids, 'members', $otherMember->getId());
            $this->remember($ids, 'members', $inactiveMember->getId());

            $memberTeam = (new GuildTeam())
                ->setGuild($guild)
                ->setName('Member team')
                ->setLeader($member)
                ->addMember($member);
            $otherTeam = (new GuildTeam())
                ->setGuild($guild)
                ->setName('Other team')
                ->addMember($otherMember);
            $em->persist($memberTeam);
            $em->persist($otherTeam);
            $em->flush();
            $this->remember($ids, 'teams', $memberTeam->getId());
            $this->remember($ids, 'teams', $otherTeam->getId());

            $now = new \DateTimeImmutable();
            $visibleTitle = 'Widget <Raid> visible';
            $visibleEvent = $this->createEvent($em, $ids, $guild, $visibleTitle, $now->modify('+1 day'))
                ->setDescription('PRIVATE-DESCRIPTION-'.$suffix)
                ->setLocation('PRIVATE-LOCATION-'.$suffix);
            $em->flush();
            $this->createEvent($em, $ids, $guild, 'Visible team event '.$suffix, $now->modify('+2 days'), GuildEvent::STATUS_PLANNED, $memberTeam);
            $this->createEvent($em, $ids, $guild, 'Hidden other-team event '.$suffix, $now->modify('+3 days'), GuildEvent::STATUS_PLANNED, $otherTeam);
            $this->createEvent($em, $ids, $guild, 'Hidden cancelled event '.$suffix, $now->modify('+4 days'), GuildEvent::STATUS_CANCELLED);
            $this->createEvent($em, $ids, $guild, 'Hidden past event '.$suffix, $now->modify('-1 day'));
            $this->createEvent($em, $ids, $disabledGuild, 'Hidden disabled guild event '.$suffix, $now->modify('+5 days'));
            $this->createEvent($em, $ids, $disabledGameGuild, 'Hidden disabled game event '.$suffix, $now->modify('+6 days'));
            $this->createEvent($em, $ids, $inactiveMemberGuild, 'Hidden inactive membership event '.$suffix, $now->modify('+7 days'));

            $signup = (new GuildEventSignup())
                ->setEvent($visibleEvent)
                ->setMember($member)
                ->setUser($user)
                ->setNote('PRIVATE-SIGNUP-NOTE-'.$suffix);
            $em->persist($signup);
            $em->flush();
            $this->remember($ids, 'signups', $signup->getId());

            $this->saveHomeWidget($client, 6);

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            $guestHtml = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('/login', $guestHtml);
            self::assertStringNotContainsString('Widget &lt;Raid&gt; visible', $guestHtml);
            self::assertStringNotContainsString('Visible team event '.$suffix, $guestHtml);

            $client->loginUser($user);
            $authenticatedPage = $client->request('GET', '/');
            self::assertResponseIsSuccessful();

            $html = (string) $client->getResponse()->getContent();
            self::assertSelectorTextContains('#widget-guild-events-widget', 'Nächste Gilden-Termine');
            self::assertSelectorTextContains('#widget-guild-events-widget', $visibleTitle);
            self::assertSelectorTextContains('#widget-guild-events-widget', 'Visible team event '.$suffix);
            self::assertCount(2, $authenticatedPage->filter('#widget-guild-events-widget .guild-event-list li'));
            self::assertStringContainsString('Widget &lt;Raid&gt; visible', $html);
            self::assertStringContainsString('href="/guild-area/'.$guild->getId().'"', $html);
            foreach ([
                'Hidden other-team event '.$suffix,
                'Hidden cancelled event '.$suffix,
                'Hidden past event '.$suffix,
                'Hidden disabled guild event '.$suffix,
                'Hidden disabled game event '.$suffix,
                'Hidden inactive membership event '.$suffix,
                'PRIVATE-DESCRIPTION-'.$suffix,
                'PRIVATE-LOCATION-'.$suffix,
                'PRIVATE-SIGNUP-NOTE-'.$suffix,
            ] as $privateValue) {
                self::assertStringNotContainsString($privateValue, $html);
            }
            $this->assertPrivateNoStore($client);
            self::assertSame('no-cache', $client->getResponse()->headers->get('Pragma'));

            $client->loginUser($unaffiliatedUser);
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#widget-guild-events-widget', 'Für deine Gilden stehen keine kommenden Termine an.');
            $this->assertPrivateNoStore($client);
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    public function testQueryOrdersTiesByIdDeduplicatesMultipleCharactersAndCapsResults(): void
    {
        $client = static::createClient();
        $ids = $this->newIds();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));

        try {
            $user = $this->newUser($suffix.'-member');
            $game = $this->newGame($suffix, true);
            $guild = $this->newGuild($game, $suffix.'-guild', true);
            $firstCharacter = $this->newMember($guild, $user, 'First character');
            $secondCharacter = $this->newMember($guild, $user, 'Second character');
            foreach ([$user, $game, $guild, $firstCharacter, $secondCharacter] as $entity) {
                $em->persist($entity);
            }
            $em->flush();
            $this->remember($ids, 'users', $user->getId());
            $this->remember($ids, 'games', $game->getId());
            $this->remember($ids, 'guilds', $guild->getId());
            $this->remember($ids, 'members', $firstCharacter->getId());
            $this->remember($ids, 'members', $secondCharacter->getId());

            $startsAt = new \DateTimeImmutable('+1 day');
            for ($index = 1; $index <= 15; ++$index) {
                $this->createEvent($em, $ids, $guild, 'Tie '.$index, $startsAt);
            }

            $query = $client->getContainer()->get(UpcomingGuildEventsQuery::class);
            $events = $query->findForUser($user, 100, new \DateTimeImmutable());
            self::assertCount(UpcomingGuildEventsQuery::MAX_RESULTS, $events);
            self::assertSame(
                array_slice($ids['events'], 0, UpcomingGuildEventsQuery::MAX_RESULTS),
                array_map(static fn (GuildEvent $event): int => (int) $event->getId(), $events),
            );

            self::assertSame(
                array_slice($ids['events'], 0, 4),
                array_map(static fn (GuildEvent $event): int => (int) $event->getId(), $query->findForUser($user, 4, new \DateTimeImmutable())),
            );
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    public function testDisabledGamingHidesTheWidgetAndItsData(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $priorState = $em->find(CmsModuleState::class, 'gaming');
        $priorEnabled = $priorState?->isEnabled();
        $state = $priorState ?? (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
        $state->setEnabled(true);
        $em->persist($state);
        $em->flush();

        try {
            $this->saveHomeWidget($client, 6);

            $state->setEnabled(false);
            $em->flush();

            $registry = $client->getContainer()->get(WidgetRegistry::class);
            $availableKeys = array_map(
                static fn (WidgetDefinition $definition): string => $definition->key,
                $registry->availableDefinitions(),
            );
            self::assertNotContains(UpcomingGuildEventsWidgetProvider::KEY, $availableKeys);
            self::assertSame([], $registry->data(UpcomingGuildEventsWidgetProvider::KEY, ['count' => 6]));

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('#widget-guild-events-widget');
        } finally {
            $cleanupEm = $client->getContainer()->get(EntityManagerInterface::class);
            $layout = $cleanupEm->find(PageLayout::class, 'home');
            if ($layout instanceof PageLayout) {
                $cleanupEm->remove($layout);
            }
            $currentState = $cleanupEm->find(CmsModuleState::class, 'gaming');
            if ($priorState === null) {
                if ($currentState instanceof CmsModuleState) {
                    $cleanupEm->remove($currentState);
                }
            } elseif ($currentState instanceof CmsModuleState && $priorEnabled !== null) {
                $currentState->setEnabled($priorEnabled);
            }
            $cleanupEm->flush();
        }
    }

    /** @return array<string, list<int>> */
    private function newIds(): array
    {
        return [
            'signups' => [],
            'events' => [],
            'teams' => [],
            'members' => [],
            'guilds' => [],
            'games' => [],
            'users' => [],
        ];
    }

    /** @param array<string, list<int>> $ids */
    private function remember(array &$ids, string $collection, ?int $id): void
    {
        if ($id !== null) {
            $ids[$collection][] = $id;
        }
    }

    private function newUser(string $suffix): User
    {
        return (new User())
            ->setEmail('guild-widget-'.$suffix.'@example.test')
            ->setDisplayName('Guild widget')
            ->verifyEmail();
    }

    private function newGame(string $suffix, bool $enabled): Game
    {
        return (new Game())
            ->setName('Widget game '.$suffix)
            ->setSlug('widget-game-'.$suffix)
            ->setEnabled($enabled);
    }

    private function newGuild(Game $game, string $suffix, bool $enabled): Guild
    {
        return (new Guild())
            ->setGame($game)
            ->setName('Widget guild '.$suffix)
            ->setSlug('widget-guild-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Synthetic widget fixture')
            ->setEnabled($enabled);
    }

    private function newMember(Guild $guild, User $user, string $name, bool $active = true): GuildMember
    {
        return (new GuildMember())
            ->setGuild($guild)
            ->setUser($user)
            ->setCharacterName($name)
            ->setActive($active);
    }

    /** @param array<string, list<int>> $ids */
    private function createEvent(
        EntityManagerInterface $em,
        array &$ids,
        Guild $guild,
        string $title,
        \DateTimeImmutable $startsAt,
        string $status = GuildEvent::STATUS_PLANNED,
        ?GuildTeam $team = null,
    ): GuildEvent {
        $event = (new GuildEvent())
            ->setGuild($guild)
            ->setTeam($team)
            ->setTitle($title)
            ->setStartsAt($startsAt)
            ->setStatus($status);
        $em->persist($event);
        $em->flush();
        $this->remember($ids, 'events', $event->getId());

        return $event;
    }

    private function saveHomeWidget(KernelBrowser $client, int $count): void
    {
        $container = $client->getContainer();
        $validator = $container->get(LayoutValidator::class);
        $document = $validator->defaults('nebula')->toArray();
        $theme = $container->get(ThemeRegistry::class)->get($document['theme']);
        $document['widgets'][] = [
            'id' => 'guild-events-widget',
            'type' => UpcomingGuildEventsWidgetProvider::KEY,
            'region' => $theme->regions[0] ?? 'main',
            'enabled' => true,
            'config' => ['count' => $count],
        ];
        $document = $validator->validate($document)->toArray();

        $em = $container->get(EntityManagerInterface::class);
        $existing = $em->find(PageLayout::class, 'home');
        if ($existing instanceof PageLayout) {
            $em->remove($existing);
            $em->flush();
        }
        $layout = new PageLayout('home');
        $layout->replace($document);
        $em->persist($layout);
        $em->flush();
    }

    private function assertPrivateNoStore(KernelBrowser $client): void
    {
        $cacheControl = strtolower((string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('max-age=0', $cacheControl);
    }

    /** @param array<string, list<int>> $ids */
    private function cleanup(KernelBrowser $client, array $ids): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $layout = $em->find(PageLayout::class, 'home');
        if ($layout instanceof PageLayout) {
            $em->remove($layout);
        }

        foreach ([
            'signups' => GuildEventSignup::class,
            'events' => GuildEvent::class,
            'teams' => GuildTeam::class,
            'members' => GuildMember::class,
            'guilds' => Guild::class,
            'games' => Game::class,
            'users' => User::class,
        ] as $collection => $class) {
            foreach ($ids[$collection] as $id) {
                $entity = $em->find($class, $id);
                if ($entity !== null) {
                    $em->remove($entity);
                }
            }
        }

        $em->flush();
    }
}

