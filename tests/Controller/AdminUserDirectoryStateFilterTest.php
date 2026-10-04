<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Security\CmsPermission;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminUserDirectoryStateFilterTest extends WebTestCase
{
    public function testActiveFilterExcludesDisabledAndCurrentlyLockedAccounts(): void
    {
        $client = static::createClient();
        $scope = 'directory-filter-'.bin2hex(random_bytes(5));

        $manager = $this->createUser($client, $scope, 'manager', [CmsPermission::USERS]);
        $enabled = $this->createUser($client, $scope, 'enabled');
        $currentlyLocked = $this->createUser($client, $scope, 'currently-locked')
            ->setLockedUntil(new DateTimeImmutable('+1 day'));
        $disabledWithExpiredLock = $this->createUser($client, $scope, 'disabled-expired-lock')
            ->setActive(false)
            ->setLockedUntil(new DateTimeImmutable('-1 day'));
        $enabledWithExpiredLock = $this->createUser($client, $scope, 'enabled-expired-lock')
            ->setLockedUntil(new DateTimeImmutable('-1 day'));
        $this->entityManager($client)->flush();

        $client->loginUser($manager);
        $crawler = $client->request('GET', '/admin/users?'.http_build_query([
            'q' => $scope,
            'state' => 'active',
        ]));

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('tbody tr')->each(static fn (Crawler $row): string => $row->text());
        $table = implode("\n", $rows);

        self::assertCount(3, $rows);
        self::assertStringContainsString($manager->getDisplayName(), $table);
        self::assertStringContainsString($enabled->getDisplayName(), $table);
        self::assertStringContainsString($enabledWithExpiredLock->getDisplayName(), $table);
        self::assertStringNotContainsString($currentlyLocked->getDisplayName(), $table);
        self::assertStringNotContainsString($disabledWithExpiredLock->getDisplayName(), $table);
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $scope, string $label, array $permissions = []): User
    {
        $user = (new User())
            ->setEmail('directory-'.$label.'-'.bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName('Directory '.$scope.' '.$label)
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions);
        $this->entityManager($client)->persist($user);

        return $user;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
