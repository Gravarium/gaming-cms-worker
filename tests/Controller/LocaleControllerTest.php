<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LocaleControllerTest extends WebTestCase
{
    public function testLocaleSwitchRequiresPost(): void
    {
        $client = static::createClient();
        $client->request('GET', '/locale/de');

        self::assertResponseStatusCodeSame(405);
    }

    public function testLocaleSwitchRejectsMissingCsrfToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/locale/de');

        self::assertResponseStatusCodeSame(403);
    }
}
