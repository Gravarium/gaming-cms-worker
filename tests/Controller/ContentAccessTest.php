<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContentAccessTest extends WebTestCase
{
    public function testContentAdministrationRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/content');

        self::assertResponseRedirects('/login');
    }
}
