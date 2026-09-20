<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityControllerTest extends WebTestCase
{
    public function testLoginPageIsAvailable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Anmelden');
        self::assertSelectorTextContains('[data-passkey-login]', 'Mit Passkey anmelden');
    }

    public function testAccountSecurityRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/security');

        self::assertResponseRedirects('/login');
    }

    public function testPasskeyManagementRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/passkeys');
        self::assertResponseRedirects('/login');
    }

    public function testAdminAreaRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }
}
