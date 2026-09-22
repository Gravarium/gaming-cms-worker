<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\SiteSettingsRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LocaleControllerTest extends WebTestCase
{
    public function testLocaleSwitchRequiresPost(): void
    {
        $client = static::createClient();
        $client->request('GET', '/locale/de');

        self::assertResponseStatusCodeSame(405);
    }

    public function testLocaleSwitchRejectsBackslashRedirectTarget(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $token = $client->getContainer()->get('security.csrf.token_manager')->getToken('locale-switch')->getValue();

        $client->request('POST', '/locale/de', [
            '_token' => $token,
            '_target' => '/\\evil.example.test',
        ]);

        self::assertResponseRedirects('/');
    }

    public function testLocaleSwitchRejectsEncodedPathSeparatorRedirectTarget(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        $token = $client->getContainer()->get('security.csrf.token_manager')->getToken('locale-switch')->getValue();

        $client->request('POST', '/locale/de', [
            '_token' => $token,
            '_target' => '/%5cevil.example.test',
        ]);

        self::assertResponseRedirects('/');
    }

    public function testLocaleSwitchRejectsMissingCsrfToken(): void
    {
        $client = static::createClient();
        $client->request('POST', '/locale/de');

        self::assertResponseStatusCodeSame(403);
    }
}
