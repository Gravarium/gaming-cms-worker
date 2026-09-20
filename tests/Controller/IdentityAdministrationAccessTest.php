<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class IdentityAdministrationAccessTest extends WebTestCase
{
    #[DataProvider('protectedRoutes')]
    public function testIdentityAdministrationRequiresLogin(string $method, string $path): void
    {
        $client = static::createClient();
        $client->request($method, $path);
        self::assertResponseRedirects('/login');
    }

    /** @return iterable<string, array{string, string}> */
    public static function protectedRoutes(): iterable
    {
        yield 'roles' => ['GET', '/admin/access-roles'];
        yield 'new role' => ['GET', '/admin/access-roles/new'];
        yield 'users' => ['GET', '/admin/users'];
        yield 'sessions' => ['GET', '/admin/users/1/sessions'];
    }
}
