<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccessRole;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminAccessRoleMembersTest extends WebTestCase
{
    public function testRoleListLinksToMemberRoster(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, 'Role-list manager', [CmsPermission::USERS]);
        $client->loginUser($manager);
        $role = $this->role($client, [CmsPermission::USERS]);

        $client->request('GET', '/admin/access-roles');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/access-roles/'.$role->getId().'/members"]');
    }

    public function testRosterShowsOnlyMembersAndUsesExistingUserEditRoute(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, 'Roster manager', [CmsPermission::USERS]);
        $role = $this->role($client, [CmsPermission::USERS]);
        $member = $this->user($client, 'Assigned member', [], $role);
        $unrelated = $this->user($client, 'Unrelated account');
        $client->loginUser($manager);

        $crawler = $client->request('GET', $this->path($role));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Assigned member');
        self::assertSelectorTextContains('tbody', $member->getEmail());
        self::assertSelectorTextNotContains('tbody', 'Unrelated account');
        self::assertSelectorExists('a[href="/admin/users/'.$member->getId().'/edit"]');
        self::assertSelectorNotExists('a[href="/admin/users/'.$unrelated->getId().'/edit"]');
    }

    public function testSearchAndAccountStateFilters(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, 'Search manager', [CmsPermission::USERS]);
        $role = $this->role($client, [CmsPermission::USERS]);
        $active = $this->user($client, 'Roster Search Target', [], $role);
        $locked = $this->user($client, 'Roster Locked Member', [], $role);
        $locked->setLockedUntil(new \DateTimeImmutable('+1 day'));
        $unverified = $this->user($client, 'Roster Unverified Member', [], $role, false);
        $this->em($client)->flush();
        $client->loginUser($manager);

        $client->request('GET', $this->path($role).'?'.http_build_query(['q' => 'ROSTER SEARCH TARGET', 'state' => 'active']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('p[aria-live="polite"]', '1 Treffer');
        self::assertSelectorTextContains('tbody', 'Roster Search Target');
        self::assertSelectorTextNotContains('tbody', 'Roster Locked Member');

        $emailSearch = strstr($active->getEmail(), '@', true);
        self::assertNotFalse($emailSearch);
        $client->request('GET', $this->path($role).'?'.http_build_query(['q' => $emailSearch]));
        self::assertSelectorTextContains('tbody', $active->getEmail());

        $client->request('GET', $this->path($role).'?'.http_build_query(['state' => 'locked']));
        self::assertSelectorTextContains('p[aria-live="polite"]', '1 Treffer');
        self::assertSelectorTextContains('tbody', 'Roster Locked Member');

        $client->request('GET', $this->path($role).'?'.http_build_query(['state' => 'unverified']));
        self::assertSelectorTextContains('p[aria-live="polite"]', '1 Treffer');
        self::assertSelectorTextContains('tbody', 'Roster Unverified Member');
    }

    public function testPaginationIsStableAndPreservesSearchAndState(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, 'Pagination manager', [CmsPermission::USERS]);
        $role = $this->role($client, [CmsPermission::USERS]);
        $createdAt = new \ReflectionProperty(User::class, 'createdAt');
        $fixedTime = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');

        for ($index = 1; $index <= 27; ++$index) {
            $member = $this->user($client, sprintf('Role Page %02d', $index), [], $role);
            $createdAt->setValue($member, $fixedTime);
        }
        $this->em($client)->flush();
        $client->loginUser($manager);

        $crawler = $client->request('GET', $this->path($role).'?'.http_build_query(['q' => 'Role Page', 'state' => 'active']));
        self::assertResponseIsSuccessful();
        self::assertCount(25, $crawler->filter('tbody tr'));
        self::assertStringContainsString('Role Page 27', $crawler->filter('tbody tr')->eq(0)->text());
        self::assertStringContainsString('Role Page 03', $crawler->filter('tbody tr')->eq(24)->text());

        $nextPage = $crawler->filter('a[aria-label="Nächste Seite"]')->attr('href');
        self::assertNotNull($nextPage);
        parse_str((string) parse_url($nextPage, PHP_URL_QUERY), $filters);
        self::assertSame('Role Page', $filters['q'] ?? null);
        self::assertSame('active', $filters['state'] ?? null);
        self::assertSame('2', $filters['page'] ?? null);

        $secondPage = $client->request('GET', $nextPage);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $secondPage->filter('tbody tr'));
        self::assertStringContainsString('Role Page 02', $secondPage->filter('tbody tr')->eq(0)->text());
        self::assertStringContainsString('Role Page 01', $secondPage->filter('tbody tr')->eq(1)->text());
    }

    public function testRoleHierarchyBlocksAccessToStrongerRoleRoster(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, 'Limited role manager', [CmsPermission::USERS]);
        $role = $this->role($client, [CmsPermission::SETTINGS]);
        $client->loginUser($manager);

        $client->request('GET', $this->path($role));

        self::assertResponseStatusCodeSame(403);
    }

    public function testRosterRequiresLogin(): void
    {
        $client = static::createClient();
        $role = $this->role($client, [CmsPermission::USERS]);

        $client->request('GET', $this->path($role));

        self::assertResponseRedirects('/login');
    }

    public function testRosterRequiresUserManagementPermission(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, 'Content only manager', [CmsPermission::CONTENT]);
        $role = $this->role($client, []);
        $client->loginUser($manager);

        $client->request('GET', $this->path($role));

        self::assertResponseStatusCodeSame(403);
    }

    public function testMalformedFiltersFailClosedAndOutOfRangePageIsNotFound(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, 'Filter validation manager', [CmsPermission::USERS]);
        $role = $this->role($client, [CmsPermission::USERS]);
        $client->loginUser($manager);

        $invalidQueries = [
            '?q%5B%5D=unexpected',
            '?state=unknown',
            '?q='.str_repeat('x', 101),
            '?page=0',
            '?page=10001',
        ];
        foreach ($invalidQueries as $query) {
            $client->request('GET', $this->path($role).$query);
            self::assertResponseStatusCodeSame(400);
        }

        $client->request('GET', $this->path($role).'?'.http_build_query([
            'q' => 'No roster member '.bin2hex(random_bytes(4)),
            'page' => 2,
        ]));
        self::assertResponseStatusCodeSame(404);
    }

    public function testRosterUsesPrivateUncachedNoindexResponseAndDoesNotChangeMembership(): void
    {
        $client = static::createClient();
        $manager = $this->user($client, 'Privacy manager', [CmsPermission::USERS]);
        $role = $this->role($client, [CmsPermission::USERS]);
        $member = $this->user($client, 'Private roster member', [], $role);
        $roleId = $role->getId();
        $memberId = $member->getId();
        self::assertNotNull($roleId);
        self::assertNotNull($memberId);
        $client->loginUser($manager);

        $client->request('GET', $this->path($role));

        self::assertResponseIsSuccessful();
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        $body = $client->getResponse()->getContent();
        self::assertNotFalse($body);
        self::assertStringContainsString($member->getEmail(), $body);

        $client->request('POST', $this->path($role), ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(405);

        $this->em($client)->clear();
        $storedRole = $this->em($client)->find(AccessRole::class, $roleId);
        $storedMember = $this->em($client)->find(User::class, $memberId);
        self::assertInstanceOf(AccessRole::class, $storedRole);
        self::assertInstanceOf(User::class, $storedMember);
        self::assertTrue($storedMember->getAccessRoles()->contains($storedRole));
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, string $name, array $permissions = [], ?AccessRole $role = null, bool $verified = true): User
    {
        $user = (new User())
            ->setEmail('role-roster-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName($name)
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions);
        if ($verified) {
            $user->verifyEmail();
        }
        if ($role !== null) {
            $user->addAccessRole($role);
        }
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    /** @param list<string> $permissions */
    private function role(KernelBrowser $client, array $permissions): AccessRole
    {
        $role = (new AccessRole())
            ->setKey('roster-role-'.bin2hex(random_bytes(6)))
            ->setName('Roster role '.bin2hex(random_bytes(3)))
            ->setPermissions($permissions);
        $this->em($client)->persist($role);
        $this->em($client)->flush();

        return $role;
    }

    private function path(AccessRole $role): string
    {
        return '/admin/access-roles/'.$role->getId().'/members';
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
