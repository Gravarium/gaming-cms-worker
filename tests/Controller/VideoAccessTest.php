<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoAccessTest extends WebTestCase
{
    public function testVideoAdministrationRequiresLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/videos');
        self::assertResponseRedirects('/login');
    }

    public function testPublicVideoLibraryIsAvailable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/videos');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Videos');
    }
}
