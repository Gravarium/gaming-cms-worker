<?php

declare(strict_types=1);

namespace App\\Tests\\Controller;

use App\\Entity\\User;
use App\\Security\\CmsPermission;
use DateTimeImmutable;
use Doctrine\\ORM\\EntityManagerInterface;
use Symfony\\Bundle\\FrameworkBundle\\KernelBrowser;
use Symfony\\Bundle\\FrameworkBundle\\Test\\WebTestCase;
use Symfony\\Component\\DomCrawler\\Crawler;

final class AdminUserDirectoryTest extends WebTestCase
{
    public function testDirectoryRequiresUsersPermission(): void
    {
        $auth = $this->authenticatedClient(false);

        try {
            $auth['client']->request('GET', '/admin/users/directory');

            self::assertResponseStatusCodeSame(403);
        } finally {
            $this->cleanup($auth['client'], [$auth['userId']]);
        }
    }

    public function testExistingUserListLinksToDirectoryAndValidLargePageIsClamped(): void
    {
        $auth = $this->authenticatedClient();
        $client = $auth['client'];

        try {
            $crawler = $client->request('GET', '/admin/users');
            self::assertResponseIsSuccessful();
            self::assertSame('/admin/users/directory', $crawler->selectLink('Vollständiges Benutzerverzeichnis')->attr('href'));

            $crawler = $client->request('GET', '/admin/users/directory?'.http_build_query([
                'q' => $auth['user']->getEmail(),
                'page' => '999999999',
            ]));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', '1 Benutzerkonto · Seite 1 von 1');
            self::assertCount(1, $crawler->filter('tbody tr[data-user-id]'));
            $this->assertPrivateDirectoryHeaders($client);
        } finally {
            $this->cleanup($client, [$auth['userId']]);
        }
    }

    public function testSearchAndStateFiltersReturnTheExpectedAccountsAndHideAdminLinks(): void
    {
        $auth = $this->authenticatedClient();
        $client = $auth['client'];
        $token = 'directory-'.bin2hex(random_bytes(6));
        $active = $this->directoryUser($token, 'Name match '.$token, 'email-match', true, null, true);
        $inactive = $this->directoryUser($token, 'Inactive '.$token, 'inactive', false, null, true);
        $locked = $this->directoryUser($token, 'Locked '.$token, 'locked', true, (new DateTimeImmutable())->modify('+1 day'), true);
        $expiredLock = $this->directoryUser($token, 'Expired lock '.$token, 'expired-lock', true, (new DateTimeImmutable())->modify('-1 day'), true);
        $unverified = $this->directoryUser($token, 'Unverified '.$token, 'unverified', true, null, false);
        $admin = $this->directoryUser($token, 'Admin target '.$token, 'admin-target', true, null, true, true);
        $users = [$active, $inactive, $locked, $expiredLock, $unverified, $admin];
        $entityManager = $this->em($client);
        $userIds = [$auth['userId']];

        foreach ($users as $user) {
            $entityManager->persist($user);
        }
        $entityManager->flush();

        foreach ($users as $user) {
            $userIds[] = $this->userId($user);
        }

        try {
            $crawler = $client->request('GET', '/admin/users/directory?'.http_build_query([
                'q' => strtoupper('Name match '.$token),
            ]));
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('tbody tr[data-user-id]'));
            self::assertSelectorTextContains('tbody', 'Name match '.$token);

            $crawler = $client->request('GET', '/admin/users/directory?'.http_build_query([
                'q' => strtoupper('email-match-'.$token),
            ]));
            self::assertResponseIsSuccessful();
            self::assertCount(1, $crawler->filter('tbody tr[data-user-id]'));
            self::assertSame($this->userId($active), (int) $crawler->filter('tbody tr[data-user-id]')->attr('data-user-id'));

            $crawler = $client->request('GET', '/admin/users/directory?'.http_build_query([
                'q' => $token,
                'state' => 'active',
            ]));
            self::assertResponseIsSuccessful();
            self::assertEqualsCanonicalizing(
                [$this->userId($active), $this->userId($expiredLock), $this->userId($unverified), $this->userId($admin)],
                $this->visibleUserIds($crawler),
            );

            $crawler = $client->request('GET', '/admin/users/directory?'.http_build_query([
                'q' => $token,
                'state' => 'locked',
            ]));
            self::assertResponseIsSuccessful();
            self::assertEqualsCanonicalizing(
                [$this->userId($inactive), $this->userId($locked)],
                $this->visibleUserIds($crawler),
            );

            $crawler = $client->request('GET', '/admin/users/directory?'.http_build_query([
                'q' => $token,
                'state' => 'unverified',
            ]));
            self::assertResponseIsSuccessful();
            self::assertEquals([$this->userId($unverified)], $this->visibleUserIds($crawler));

            $crawler = $client->request('GET', '/admin/users/directory?'.http_build_query([
                'q' => 'missing-'.$token,
            ]));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', '0 Benutzerkonten');
            self::assertSelectorTextContains('main', 'Keine passenden Benutzerkonten gefunden.');

            $crawler = $client->request('GET', '/admin/users/directory?'.http_build_query([
                'q' => 'admin-target-'.$token,
            ]));
            self::assertResponseIsSuccessful();
            $adminRow = $crawler->filter('tbody tr[data-user-id]');
            self::assertCount(1, $adminRow);
            self::assertCount(0, $adminRow->filter('a[href="/admin/users/'.$this->userId($admin).'/edit"]'));
            self::assertCount(0, $adminRow->filter('a[href="/admin/users/'.$this->userId($admin).'/sessions"]'));
            $this->assertPrivateDirectoryHeaders($client);
        } finally {
            $this->cleanup($client, $userIds);
        }
    }

    public function testEveryUserCanBeReachedBeyondTheLegacyTwoHundredFiftyResultCap(): void
    {
        $auth = $this->authenticatedClient();
        $client = $auth['client'];
        $entityManager = $this->em($client);
        $token = 'full-directory-'.bin2hex(random_bytes(6));
        $users = [];
        $userIds = [$auth['userId']];

        for ($index = 0; $index < 251; ++$index) {
            $user = $this->directoryUser(
                $token,
                sprintf('Directory %s %03d', $token, $index),
                sprintf('directory-%s-%03d', $token, $index),
            );
            $entityManager->persist($user);
            $users[] = $user;
        }
        $entityManager->flush();

        foreach ($users as $user) {
            $userIds[] = $this->userId($user);
        }
        $expectedIds = array_map(fn (User $user): int => $this->userId($user), $users);
        $seenIds = [];
        $firstPageIds = [];

        try {
            for ($page = 1; $page <= 11; ++$page) {
                $crawler = $client->request('GET', '/admin/users/directory?'.http_build_query([
                    'q' => $token,
                    'state' => 'active',
                    'page' => (string) $page,
                ]));

                self::assertResponseIsSuccessful();
                self::assertSelectorTextContains('[role="status"]', '251 Benutzerkonten');
                self::assertSelectorTextContains('[role="status"]', 'Seite '.$page.' von 11');
                $visibleIds = $this->visibleUserIds($crawler);
                self::assertCount($page === 11 ? 1 : 25, $visibleIds);

                if ($page === 1) {
                    $firstPageIds = $visibleIds;
                    $firstRow = $crawler->filter('tbody tr[data-user-id]')->eq(0);
                    $firstId = (int) $firstRow->attr('data-user-id');
                    self::assertSame('/admin/users/'.$firstId.'/edit', $firstRow->filter('a')->eq(0)->attr('href'));
                    self::assertSame('/admin/users/'.$firstId.'/sessions', $firstRow->filter('a')->eq(1)->attr('href'));

                    $nextUrl = (string) $crawler->filter('nav[aria-label="Seitennavigation"] a[rel="next"]')->attr('href');
                    parse_str((string) parse_url($nextUrl, PHP_URL_QUERY), $nextQuery);
                    self::assertSame($token, $nextQuery['q'] ?? null);
                    self::assertSame('active', $nextQuery['state'] ?? null);
                    self::assertSame('2', $nextQuery['page'] ?? null);
                }

                $seenIds = [...$seenIds, ...$visibleIds];
            }

            self::assertCount(251, $seenIds);
            self::assertCount(251, array_unique($seenIds));
            self::assertEqualsCanonicalizing($expectedIds, $seenIds);

            $crawler = $client->request('GET', '/admin/users/directory?'.http_build_query([
                'q' => $token,
                'state' => 'active',
                'page' => '1',
            ]));
            self::assertResponseIsSuccessful();
            self::assertSame($firstPageIds, $this->visibleUserIds($crawler));
            $this->assertPrivateDirectoryHeaders($client);
        } finally {
            $this->cleanup($client, $userIds);
        }
    }

    public function testMalformedDirectorySearchAndPageParametersAreRejected(): void
    {
        $auth = $this->authenticatedClient();

        try {
            $uris = [
                '/admin/users/directory?page=0',
                '/admin/users/directory?page=not-a-number',
                '/admin/users/directory?page=9999999999',
                '/admin/users/directory?page%5B%5D=1',
                '/admin/users/directory?q%5B%5D=invalid',
                '/admin/users/directory?state%5B%5D=active',
                '/admin/users/directory?state=unknown',
                '/admin/users/directory?q='.str_repeat('x', 181),
                '/admin/users/directory?q=%FF',
            ];

            foreach ($uris as $uri) {
                $auth['client']->request('GET', $uri);

                self::assertResponseStatusCodeSame(400, $uri);
            }
        } finally {
            $this->cleanup($auth['client'], [$auth['userId']]);
        }
    }

    /**
     * @return array{client: KernelBrowser, user: User, userId: int}
     */
    private function authenticatedClient(bool $withUsersPermission = true): array
    {
        $client = static::createClient();
        $permissions = [CmsPermission::ACCESS];
        if ($withUsersPermission) {
            $permissions[] = CmsPermission::USERS;
        }

        $user = (new User())
            ->setEmail('admin-directory-'.bin2hex(random_bytes(8)).'@example.test')
            ->setDisplayName('Directory manager')
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash');
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        $userId = $this->userId($user);
        $client->loginUser($user);

        return ['client' => $client, 'user' => $user, 'userId' => $userId];
    }

    private function directoryUser(
        string $token,
        string $displayName,
        string $emailLocalPart,
        bool $active = true,
        ?DateTimeImmutable $lockedUntil = null,
        bool $verified = true,
        bool $admin = false,
    ): User {
        $user = (new User())
            ->setEmail($emailLocalPart.'-'.$token.'@example.test')
            ->setDisplayName($displayName)
            ->setPassword('unused-test-hash')
            ->setActive($active)
            ->setLockedUntil($lockedUntil);
        if ($verified) {
            $user->verifyEmail();
        }
        if ($admin) {
            $user->setAdmin(true);
        }

        return $user;
    }

    /**
     * @param list<int> $userIds
     */
    private function cleanup(KernelBrowser $client, array $userIds): void
    {
        $entityManager = $this->em($client);
        $entityManager->clear();

        foreach (array_values(array_unique($userIds)) as $userId) {
            $user = $entityManager->find(User::class, $userId);
            if ($user instanceof User) {
                $entityManager->remove($user);
            }
        }

        $entityManager->flush();
    }

    /**
     * @return list<int>
     */
    private function visibleUserIds(Crawler $crawler): array
    {
        return $crawler->filter('tbody tr[data-user-id]')->each(
            static fn (Crawler $row): int => (int) $row->attr('data-user-id'),
        );
    }

    private function userId(User $user): int
    {
        $id = $user->getId();
        if ($id === null) {
            throw new LogicException('Persisted test user has no identifier.');
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
