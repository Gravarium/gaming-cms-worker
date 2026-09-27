<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildEvent;
use App\Entity\GuildEventSignup;
use App\Entity\GuildMember;
use App\Entity\User;
use App\Security\CmsPermission;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminGuildCollaborationPaginationTest extends WebTestCase
{
    public function testCollaborationIndexRequiresGamingPermission(): void
    {
        $auth = $this->authenticatedClient(false);
        $fixtures = $this->createGuilds($auth['client']);
        $ids = $this->emptyCleanupIds($auth['userId'], $fixtures);

        try {
            $auth['client']->request('GET', '/admin/gaming/guild/'.$fixtures['guild']->getId().'/collaboration');

            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->cleanup($auth['client'], $ids);
        }
    }

    public function testAllGuildEventsCanBeSearchedAndReachedAcrossStablePages(): void
    {
        $auth = $this->authenticatedClient();
        $client = $auth['client'];
        $entityManager = $this->em($client);
        $fixtures = $this->createGuilds($client);
        $token = 'guild-events-'.bin2hex(random_bytes(6));
        $events = [];

        for ($index = 0; $index < 56; ++$index) {
            $status = $index < 30
                ? GuildEvent::STATUS_PLANNED
                : (($index - 30) % 2 === 0 ? GuildEvent::STATUS_DONE : GuildEvent::STATUS_CANCELLED);
            $event = (new GuildEvent())
                ->setGuild($fixtures['guild'])
                ->setCreatedBy($auth['user'])
                ->setTitle($index === 0 ? 'Title match '.$token : 'Guild event '.$token.' '.sprintf('%03d', $index))
                ->setDescription($index === 1 ? 'Description match '.$token : 'Guild event details '.$token)
                ->setStatus($status)
                ->setStartsAt((new DateTimeImmutable())->modify('+'.($index + 1).' days'));
            $entityManager->persist($event);
            $events[] = $event;
        }

        $foreignEvent = (new GuildEvent())
            ->setGuild($fixtures['otherGuild'])
            ->setTitle('Foreign '.$token)
            ->setDescription('Must not be visible in the requested guild')
            ->setStatus(GuildEvent::STATUS_PLANNED);
        $entityManager->persist($foreignEvent);

        $member = (new GuildMember())
            ->setGuild($fixtures['guild'])
            ->setCharacterName('Attendance member '.$token);
        $signupUser = $this->directoryUser($token);
        $signup = (new GuildEventSignup())
            ->setEvent($events[55])
            ->setMember($member)
            ->setUser($signupUser);
        $entityManager->persist($member);
        $entityManager->persist($signupUser);
        $entityManager->persist($signup);
        $entityManager->flush();

        $ids = $this->emptyCleanupIds($auth['userId'], $fixtures);
        $ids['memberIds'][] = $this->requireId($member->getId());
        $ids['userIds'][] = $this->requireId($signupUser->getId());
        $ids['signupIds'][] = $this->requireId($signup->getId());
        $ids['eventIds'][] = $this->requireId($foreignEvent->getId());
        foreach ($events as $event) {
            $ids['eventIds'][] = $this->requireId($event->getId());
        }

        try {
            $base = '/admin/gaming/guild/'.$fixtures['guild']->getId().'/collaboration';
            $seenIds = [];
            $firstPageIds = [];

            for ($page = 1; $page <= 3; ++$page) {
                $crawler = $client->request('GET', $base.'?'.http_build_query([
                    'q' => $token,
                    'page' => (string) $page,
                ]));

                self::assertResponseIsSuccessful();
                self::assertSelectorTextContains('[role="status"]', '56 Termine');
                self::assertSelectorTextContains('[role="status"]', 'Seite '.$page.' von 3');
                $visibleIds = $this->visibleEventIds($crawler);
                self::assertCount($page === 3 ? 6 : 25, $visibleIds);

                if ($page === 1) {
                    $firstPageIds = $visibleIds;
                    self::assertSame($this->requireId($events[55]->getId()), $visibleIds[0]);
                    $nextUrl = (string) $crawler->filter('nav[aria-label="Seitennavigation"] a[rel="next"]')->attr('href');
                    parse_str((string) parse_url($nextUrl, PHP_URL_QUERY), $nextQuery);
                    self::assertSame($token, $nextQuery['q'] ?? null);
                    self::assertSame('2', $nextQuery['page'] ?? null);

                    $topCard = $crawler->filter('article[data-event-id="'.$this->requireId($events[55]->getId()).'"]');
                    self::assertSame(
                        $base.'/event/'.$this->requireId($events[55]->getId()).'/edit',
                        $topCard->filter('a')->attr('href'),
                    );
                    self::assertCount(
                        1,
                        $topCard->filter('form[action="'.$base.'/event/'.$this->requireId($events[55]->getId()).'/delete"] input[name="_token"]'),
                    );
                    self::assertCount(
                        1,
                        $topCard->filter('form[action="'.$base.'/event/'.$this->requireId($events[55]->getId()).'/attendance/'.$this->requireId($signup->getId()).'"] input[name="_token"]'),
                    );
                }

                $seenIds = [...$seenIds, ...$visibleIds];
            }

            self::assertCount(56, $seenIds);
            self::assertCount(56, array_unique($seenIds));
            $expectedIds = array_map(fn (GuildEvent $event): int => $this->requireId($event->getId()), $events);
            self::assertEqualsCanonicalizing($expectedIds, $seenIds);

            $crawler = $client->request('GET', $base.'?'.http_build_query([
                'q' => $token,
                'page' => '1',
            ]));
            self::assertResponseIsSuccessful();
            self::assertSame($firstPageIds, $this->visibleEventIds($crawler));

            $crawler = $client->request('GET', $base.'?'.http_build_query([
                'q' => strtoupper('Title match '.$token),
            ]));
            self::assertResponseIsSuccessful();
            self::assertSame([$this->requireId($events[0]->getId())], $this->visibleEventIds($crawler));

            $crawler = $client->request('GET', $base.'?'.http_build_query([
                'q' => strtoupper('Description match '.$token),
            ]));
            self::assertResponseIsSuccessful();
            self::assertSame([$this->requireId($events[1]->getId())], $this->visibleEventIds($crawler));

            $statusIds = [
                GuildEvent::STATUS_PLANNED => [],
                GuildEvent::STATUS_DONE => [],
                GuildEvent::STATUS_CANCELLED => [],
            ];
            foreach ($events as $event) {
                $statusIds[$event->getStatus()][] = $this->requireId($event->getId());
            }
            foreach ($statusIds as $status => $expectedIds) {
                $query = ['q' => $token, 'status' => $status];
                $crawler = $client->request('GET', $base.'?'.http_build_query($query));
                self::assertResponseIsSuccessful();
                self::assertSelectorTextContains(
                    '[role="status"]',
                    count($expectedIds).' '.(count($expectedIds) === 1 ? 'Termin' : 'Termine'),
                );

                if ($status === GuildEvent::STATUS_PLANNED) {
                    $firstPageStatusIds = $this->visibleEventIds($crawler);
                    self::assertCount(25, $firstPageStatusIds);
                    self::assertSelectorTextContains('[role="status"]', 'Seite 1 von 2');
                    $nextUrl = (string) $crawler->filter('nav[aria-label="Seitennavigation"] a[rel="next"]')->attr('href');
                    parse_str((string) parse_url($nextUrl, PHP_URL_QUERY), $nextQuery);
                    self::assertSame($token, $nextQuery['q'] ?? null);
                    self::assertSame(GuildEvent::STATUS_PLANNED, $nextQuery['status'] ?? null);
                    self::assertSame('2', $nextQuery['page'] ?? null);

                    $crawler = $client->request('GET', $base.'?'.http_build_query($nextQuery));
                    self::assertResponseIsSuccessful();
                    self::assertSelectorTextContains('[role="status"]', 'Seite 2 von 2');
                    $allStatusIds = [...$firstPageStatusIds, ...$this->visibleEventIds($crawler)];
                    self::assertCount(count($expectedIds), $allStatusIds);
                    self::assertCount(count($expectedIds), array_unique($allStatusIds));
                    self::assertEqualsCanonicalizing($expectedIds, $allStatusIds);
                } else {
                    self::assertEqualsCanonicalizing($expectedIds, $this->visibleEventIds($crawler));
                }
            }

            $crawler = $client->request('GET', $base.'?'.http_build_query([
                'q' => 'missing-'.$token,
            ]));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', '0 Termine');
            self::assertSelectorTextContains('main', 'Noch keine Termine vorhanden.');

            $crawler = $client->request('GET', $base.'?'.http_build_query([
                'q' => $token,
                'page' => '999999999',
            ]));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', 'Seite 3 von 3');
            $this->assertPrivateDirectoryHeaders($client);
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    public function testUnknownGuildEventsNeverAppearInTheSelectedGuildList(): void
    {
        $auth = $this->authenticatedClient();
        $client = $auth['client'];
        $fixtures = $this->createGuilds($client);
        $token = 'guild-isolation-'.bin2hex(random_bytes(6));
        $foreignEvent = (new GuildEvent())
            ->setGuild($fixtures['otherGuild'])
            ->setTitle('Secret '.$token)
            ->setDescription('Other guild only')
            ->setStatus(GuildEvent::STATUS_PLANNED);
        $this->em($client)->persist($foreignEvent);
        $this->em($client)->flush();

        $ids = $this->emptyCleanupIds($auth['userId'], $fixtures);
        $ids['eventIds'][] = $this->requireId($foreignEvent->getId());

        try {
            $crawler = $client->request(
                'GET',
                '/admin/gaming/guild/'.$fixtures['guild']->getId().'/collaboration?'.http_build_query(['q' => $token]),
            );

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', '0 Termine');
            self::assertCount(0, $crawler->filter('article[data-event-id]'));
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    public function testMalformedCollaborationSearchStatusAndPageAreRejected(): void
    {
        $auth = $this->authenticatedClient();
        $client = $auth['client'];
        $fixtures = $this->createGuilds($client);
        $base = '/admin/gaming/guild/'.$fixtures['guild']->getId().'/collaboration';
        $ids = $this->emptyCleanupIds($auth['userId'], $fixtures);

        try {
            $uris = [
                $base.'?page=0',
                $base.'?page=not-a-number',
                $base.'?page=9999999999',
                $base.'?page%5B%5D=1',
                $base.'?q%5B%5D=invalid',
                $base.'?status%5B%5D=planned',
                $base.'?status=unknown',
                $base.'?q='.str_repeat('x', 181),
                $base.'?q=%FF',
                $base.'?q=%00',
                $base.'?q=%1F',
                $base.'?q=%7F',
            ];

            foreach ($uris as $uri) {
                $client->request('GET', $uri);

                self::assertResponseStatusCodeSame(400, $uri);
            }
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    /**
     * @return array{client: KernelBrowser, user: User, userId: int}
     */
    private function authenticatedClient(bool $withGamingPermission = true): array
    {
        $client = static::createClient();
        $permissions = [CmsPermission::ACCESS];
        if ($withGamingPermission) {
            $permissions[] = CmsPermission::GAMING;
        }

        $user = (new User())
            ->setEmail('guild-event-admin-'.bin2hex(random_bytes(7)).'@example.test')
            ->setDisplayName('Guild event administrator')
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();
        $userId = $this->requireId($user->getId());
        $client->loginUser($user);

        return ['client' => $client, 'user' => $user, 'userId' => $userId];
    }

    private function directoryUser(string $token): User
    {
        return (new User())
            ->setEmail('guild-event-signup-'.$token.'@example.test')
            ->setDisplayName('Guild event attendee')
            ->setPassword('unused-test-hash')
            ->verifyEmail();
    }

    /**
     * @return array{game: Game, guild: Guild, otherGuild: Guild}
     */
    private function createGuilds(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(6));
        $game = (new Game())
            ->setName('Guild event game '.$suffix)
            ->setSlug('guild-event-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Guild event guild '.$suffix)
            ->setSlug('guild-event-guild-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Guild event directory test');
        $otherGuild = (new Guild())
            ->setGame($game)
            ->setName('Other event guild '.$suffix)
            ->setSlug('other-event-guild-'.$suffix)
            ->setServerName('Other test server')
            ->setDescription('Other guild event directory test');
        $em = $this->em($client);
        $em->persist($game);
        $em->persist($guild);
        $em->persist($otherGuild);
        $em->flush();

        return ['game' => $game, 'guild' => $guild, 'otherGuild' => $otherGuild];
    }

    /**
     * @param array{game: Game, guild: Guild, otherGuild: Guild} $fixtures
     * @return array{eventIds: list<int>, signupIds: list<int>, memberIds: list<int>, userIds: list<int>, guildIds: list<int>, gameIds: list<int>}
     */
    private function emptyCleanupIds(int $authUserId, array $fixtures): array
    {
        return [
            'eventIds' => [],
            'signupIds' => [],
            'memberIds' => [],
            'userIds' => [$authUserId],
            'guildIds' => [$this->requireId($fixtures['guild']->getId()), $this->requireId($fixtures['otherGuild']->getId())],
            'gameIds' => [$this->requireId($fixtures['game']->getId())],
        ];
    }

    /**
     * @param array{eventIds: list<int>, signupIds: list<int>, memberIds: list<int>, userIds: list<int>, guildIds: list<int>, gameIds: list<int>} $ids
     */
    private function cleanup(KernelBrowser $client, array $ids): void
    {
        $em = $this->em($client);
        $em->clear();

        $this->removeEntities($em, GuildEventSignup::class, $ids['signupIds']);
        $this->removeEntities($em, GuildEvent::class, $ids['eventIds']);
        $this->removeEntities($em, GuildMember::class, $ids['memberIds']);
        $this->removeEntities($em, User::class, $ids['userIds']);
        $this->removeEntities($em, Guild::class, $ids['guildIds']);
        $this->removeEntities($em, Game::class, $ids['gameIds']);
        $em->flush();
    }

    /**
     * @param class-string $class
     * @param list<int> $ids
     */
    private function removeEntities(EntityManagerInterface $em, string $class, array $ids): void
    {
        foreach ($ids as $id) {
            $entity = $em->find($class, $id);
            if ($entity !== null) {
                $em->remove($entity);
            }
        }
    }

    /**
     * @return list<int>
     */
    private function visibleEventIds(Crawler $crawler): array
    {
        return $crawler->filter('article[data-event-id]')->each(
            static fn (Crawler $article): int => (int) $article->attr('data-event-id'),
        );
    }

    private function requireId(?int $id): int
    {
        if ($id === null) {
            throw new \LogicException('Persisted fixture has no identifier.');
        }

        return $id;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }

    private function assertPrivateDirectoryHeaders(KernelBrowser $client): void
    {
        $cacheControl = strtolower((string) $client->getResponse()->headers->get('Cache-Control'));
        $directives = array_map('trim', explode(',', $cacheControl));
        self::assertContains('private', $directives);
        self::assertContains('no-store', $directives);
        self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'));
    }
}
