<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GamingAccessTest extends WebTestCase
{
    public function testGamingAdministrationRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/gaming');
        self::assertResponseRedirects('/login');
    }

    public function testPublicGamingOverviewIsAvailable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/gaming');
        self::assertResponseIsSuccessful();
    }
}
