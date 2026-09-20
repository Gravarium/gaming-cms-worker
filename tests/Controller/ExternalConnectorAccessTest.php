<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExternalConnectorAccessTest extends WebTestCase
{
    #[DataProvider('protectedRoutes')]
    public function testConnectorRoutesRequireLogin(string $method, string $route): void
    {
        $client = static::createClient();
        $client->request($method, $route);

        self::assertResponseRedirects('/login');
    }

    public static function protectedRoutes(): iterable
    {
        yield ['GET', '/admin/connectors'];
        yield ['GET', '/admin/connectors/new'];
        yield ['GET', '/admin/connectors/1/edit'];
        yield ['POST', '/admin/connectors/1/toggle'];
        yield ['POST', '/admin/connectors/1/delete'];
    }
}
