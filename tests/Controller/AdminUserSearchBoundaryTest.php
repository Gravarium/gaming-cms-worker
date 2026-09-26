<?php

declare(strict_types=1);

namespace App\\Tests\\Controller;

use App\\Entity\\User;
use App\\Security\\CmsPermission;
use Doctrine\\ORM\\EntityManagerInterface;
use Symfony\\Bundle\\FrameworkBundle\\KernelBrowser;
use Symfony\\Bundle\\FrameworkBundle\\Test\\WebTestCase;

final class AdminUserSearchBoundaryTest extends WebTestCase
{
    public function testAdminUserSearchPreservesTheLimitAndTruncatesOversizedQueries(): void
    {
        $client = static::createClient();
        $actor = (new User())
            ->setEmail('search-admin-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Search administrator')
            ->setPermissions([CmsPermission::USERS])
            ->setPassword('unused-test-hash');
        $this->entityManager($client)->persist($actor);
        $this->entityManager($client)->flush();
        $client->loginUser($actor);

        $limit = str_repeat('x', 190);
        $crawler = $client->request('GET', '/admin/users?q='.rawurlencode($limit));
        self::assertResponseIsSuccessful();
        self::assertSame($limit, $crawler->filter('input[name="q"]')->attr('value'));

        $crawler = $client->request('GET', '/admin/users?q='.rawurlencode($limit.'x'));
        self::assertResponseIsSuccessful();
        self::assertSame($limit, $crawler->filter('input[name="q"]')->attr('value'));
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
