<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SiteConfigurationAccessTest extends WebTestCase
{
    #[DataProvider('protectedRoutes')]
    public function testConfigurationRoutesRequireLogin(string $route): void
    {
        $client = static::createClient();
        $client->request('GET', $route);

        self::assertResponseRedirects('/login');
    }

    public static function protectedRoutes(): iterable
    {
        yield ['/admin/settings'];
        yield ['/admin/categories'];
        yield ['/admin/menu'];
    }
}
