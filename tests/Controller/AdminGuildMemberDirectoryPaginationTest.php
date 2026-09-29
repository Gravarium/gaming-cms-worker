<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\GuildMember;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminGuildMemberDirectoryPaginationTest extends WebTestCase
{
    public function testMemberDirectoryRequiresGamingManagementPermission(): void
    {
        $auth = $this->authenticatedClient(false);
        $fixtures = $this->createGuilds($auth['client']);
        $ids = $this->emptyCleanupIds($auth['userId'], $fixtures);

        try {
            $auth['client']->request('GET', '/admin/gaming/guild/'.$fixtures['guild']->getId().'/members');

            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->cleanup($auth['client'], $ids);
        }
    }

    public function testRosterCanBeSearchedFilteredAndReachedAcrossStablePages(): void
    {
        $auth = $this->authenticatedClient();
        $client = $auth['client'];
        $entityManager = $this->em($client);
        $fixtures = $this->createGuilds($client);
        $token = 'guild-roster-'.bin2hex(random_bytes(6));
        $members = [];

        for ($index = 0; $index < 56; ++$index) {
            $characterName = sprintf('Roster %s %03d', $token, $index);
            if ($index === 1) {
                $characterName = sprintf('Roster %s %03d', $token, 0);
            }
            $member = (new GuildMember())
                ->setGuild($fixtures['guild'])
                ->setCharacterName($characterName)
                ->setPosition(0)
                ->setActive($index < 30);
            $entityManager->persist($member);
            $members[] = $member;
        }

        $foreignMember = (new GuildMember())
            ->setGuild($fixtures['otherGuild'])
            ->setCharacterName('Roster '.$token.' 056')
            ->setPosition(0)
            ->setActive(true);
        $entityManager->persist($foreignMember);
        $entityManager->flush();

        $ids = $this->emptyCleanupIds($auth['userId'], $fixtures);
        foreach ($members as $member) {
            $ids['memberIds'][] = $this->requireId($member->getId());
        }
        $ids['memberIds'][] = $this->requireId($foreignMember->getId());

        try {
            $base = '/admin/gaming/guild/'.$fixtures['guild']->getId().'/members';
            $expectedIds = array_map(fn (GuildMember $member): int => $this->requireId($member->getId()), $members);
            $seenIds = [];
            $firstPageIds = [];

            for ($page = 1; $page <= 3; ++$page) {
                $crawler = $client->request('GET', $base.'?'.http_build_query([
                    'q' => $token,
                    'page' => (string) $page,
                ]));

                self::assertResponseIsSuccessful();
                self::assertSelectorTextContains('[role="status"]', '56 Mitglieder');
                self::assertSelectorTextContains('[role="status"]', 'Seite '.$page.' von 3');
                $visibleIds = $this->visibleMemberIds($crawler);
                self::assertCount($page === 3 ? 6 : 25, $visibleIds);

                if ($page === 1) {
                    $firstPageIds = $visibleIds;
                    self::assertSame($expectedIds[0], $visibleIds[0]);
                    self::assertSame($expectedIds[1], $visibleIds[1]);
                    $nextUrl = (string) $crawler->filter('nav[aria-label="Seitennavigation"] a[rel="next"]')->attr('href');
                    parse_str((string) parse_url($nextUrl, PHP_URL_QUERY), $nextQuery);
                    self::assertSame($token, $nextQuery['q'] ?? null);
                    self::assertSame('2', $nextQuery['page'] ?? null);

                    $firstRow = $crawler->filter('tr[data-member-id="'.$this->requireId($members[0]->getId()).'"]');
                    self::assertSame(
                        $base.'/'.$this->requireId($members[0]->getId()).'/edit',
                        $firstRow->filter('a')->attr('href'),
                    );
                    self::assertCount(
                        1,
                        $firstRow->filter('form[action="'.$base.'/'.$this->requireId($members[0]->getId()).'/delete"] input[name="_token"]'),
                    );
                    self::assertCount(25, $crawler->filter('tr[data-member-id] form input[name="_token"]'));
                }

                $seenIds = [...$seenIds, ...$visibleIds];
            }

            self::assertCount(56, $seenIds);
            self::assertCount(56, array_unique($seenIds));
            self::assertEqualsCanonicalizing($expectedIds, $seenIds);

            $crawler = $client->request('GET', $base.'?'.http_build_query([
                'q' => mb_strtoupper('Roster '.$token.' 005'),
            ]));
            self::assertResponseIsSuccessful();
            self::assertSame([$expectedIds[5]], $this->visibleMemberIds($crawler));

            $statusIds = [
                'active' => [],
                'inactive' => [],
            ];
            foreach ($members as $member) {
                $statusIds[$member->isActive() ? 'active' : 'inactive'][] = $this->requireId($member->getId());
            }

            foreach ($statusIds as $status => $expectedStatusIds) {
                $crawler = $client->request('GET', $base.'?'.http_build_query([
                    'q' => $token,
                    'status' => $status,
                ]));
                self::assertResponseIsSuccessful();
                self::assertSelectorTextContains(
                    '[role="status"]',
                    count($expectedStatusIds).' '.(count($expectedStatusIds) === 1 ? 'Mitglied' : 'Mitglieder'),
                );
                self::assertSelectorTextContains('[role="status"]', 'Seite 1 von 2');

                $firstStatusPageIds = $this->visibleMemberIds($crawler);
                self::assertCount(25, $firstStatusPageIds);
                $nextUrl = (string) $crawler->filter('nav[aria-label="Seitennavigation"] a[rel="next"]')->attr('href');
                parse_str((string) parse_url($nextUrl, PHP_URL_QUERY), $nextQuery);
                self::assertSame($token, $nextQuery['q'] ?? null);
                self::assertSame($status, $nextQuery['status'] ?? null);
                self::assertSame('2', $nextQuery['page'] ?? null);

                $crawler = $client->request('GET', $base.'?'.http_build_query($nextQuery));
                self::assertResponseIsSuccessful();
                self::assertSelectorTextContains('[role="status"]', 'Seite 2 von 2');
                $allStatusPageIds = [...$firstStatusPageIds, ...$this->visibleMemberIds($crawler)];
                self::assertCount(count($expectedStatusIds), $allStatusPageIds);
                self::assertCount(count($expectedStatusIds), array_unique($allStatusPageIds));
                self::assertEqualsCanonicalizing($expectedStatusIds, $allStatusPageIds);
            }

            $crawler = $client->request('GET', $base.'?'.http_build_query(['q' => 'missing-'.$token]));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', '0 Mitglieder');
            self::assertSelectorTextContains('main', 'Keine passenden Mitglieder gefunden.');

            $client->request('GET', $base.'?'.http_build_query([
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

    public function testMembersFromOtherGuildsAreNeverReturned(): void
    {
        $auth = $this->authenticatedClient();
        $client = $auth['client'];
        $fixtures = $this->createGuilds($client);
        $token = 'guild-isolation-'.bin2hex(random_bytes(6));
        $foreignMember = (new GuildMember())
            ->setGuild($fixtures['otherGuild'])
            ->setCharacterName('Secret '.$token)
            ->setActive(true);
        $this->em($client)->persist($foreignMember);
        $this->em($client)->flush();

        $ids = $this->emptyCleanupIds($auth['userId'], $fixtures);
        $ids['memberIds'][] = $this->requireId($foreignMember->getId());

        try {
            $crawler = $client->request(
                'GET',
                '/admin/gaming/guild/'.$fixtures['guild']->getId().'/members?'.http_build_query(['q' => $token]),
            );

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', '0 Mitglieder');
            self::assertCount(0, $crawler->filter('tr[data-member-id]'));
        } finally {
            $this->cleanup($client, $ids);
        }
    }

    public function testMalformedMemberSearchStatusAndPageAreRejected(): void
    {
        $auth = $this->authenticatedClient();
        $client = $auth['client'];
        $fixtures = $this->createGuilds($client);
        $base = '/admin/gaming/guild/'.$fixtures['guild']->getId().'/members';
        $ids = $this->emptyCleanupIds($auth['userId'], $fixtures);

        try {
            $uris = [
                $base.'?page=0',
                $base.'?page=not-a-number',
                $base.'?page=9999999999',
                $base.'?page%5B%5D=1',
                $base.'?q%5B%5D=invalid',
                $base.'?status%5B%5D=active',
                $base.'?status=unknown',
                $base.'?q='.str_repeat('x', 121),
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
            ->setEmail('guild-member-admin-'.bin2hex(random_bytes(7)).'@example.test')
            ->setDisplayName('Guild member administrator')
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();
        $userId = $this->requireId($user->getId());
        $client->loginUser($user);

        return ['client' => $client, 'user' => $user, 'userId' => $userId];
    }

    /**
     * @return array{game: Game, guild: Guild, otherGuild: Guild}
     */
    private function createGuilds(KernelBrowser $client): array
    {
        $suffix = bin2hex(random_bytes(6));
        $game = (new Game())
            ->setName('Guild member game '.$suffix)
            ->setSlug('guild-member-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Guild member guild '.$suffix)
            ->setSlug('guild-member-guild-'.$suffix)
            ->setServerName('Test server')
            ->setDescription('Guild member directory test');
        $otherGuild = (new Guild())
            ->setGame($game)
            ->setName('Other member guild '.$suffix)
            ->setSlug('other-member-guild-'.$suffix)
            ->setServerName('Other test server')
            ->setDescription('Other guild member directory test');
        $em = $this->em($client);
        $em->persist($game);
        $em->persist($guild);
        $em->persist($otherGuild);
        $em->flush();

        return ['game' => $game, 'guild' => $guild, 'otherGuild' => $otherGuild];
    }

    /**
     * @param array{game: Game, guild: Guild, otherGuild: Guild} $fixtures
     * @return array{memberIds: list<int>, userIds: list<int>, guildIds: list<int>, gameIds: list<int>}
     */
    private function emptyCleanupIds(int $authUserId, array $fixtures): array
    {
        return [
            'memberIds' => [],
            'userIds' => [$authUserId],
            'guildIds' => [$this->requireId($fixtures['guild']->getId()), $this->requireId($fixtures['otherGuild']->getId())],
            'gameIds' => [$this->requireId($fixtures['game']->getId())],
        ];
    }

    /**
     * @param array{memberIds: list<int>, userIds: list<int>, guildIds: list<int>, gameIds: list<int>} $ids
     */
    private function cleanup(KernelBrowser $client, array $ids): void
    {
        $em = $this->em($client);
        $em->clear();

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
    private function visibleMemberIds(Crawler $crawler): array
    {
        return $crawler->filter('tr[data-member-id]')->each(
            static fn (Crawler $row): int => (int) $row->attr('data-member-id'),
        );
    }

    private function requireId(?int $id): int
    {
        if ($id === null) {
            throw new LogicException('Persisted fixture has no identifier.');
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
