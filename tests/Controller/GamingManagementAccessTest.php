<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GamingManagementAccessTest extends WebTestCase
{
    public function testMemberAdministrationRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/gaming/guild/1/members');
        self::assertResponseRedirects('/login');
    }

    public function testApplicationAdministrationRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/gaming/applications');
        self::assertResponseRedirects('/login');
    }
}
