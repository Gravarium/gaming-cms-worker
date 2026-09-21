<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
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
    public function testLocaleSwitchRejectsBackslashAndEncodedAuthorityRedirects(): void
    {
        foreach (['/\\evil.example/path', '/%2F%2Fevil.example/path', '/%255Cevil.example/path'] as $target) {
            $client = static::createClient();
            $token = $client->getContainer()->get(CsrfTokenManagerInterface::class)->getToken('locale-switch')->getValue();
            $client->request('POST', '/locale/de', ['_token' => $token, '_target' => $target]);

            self::assertResponseRedirects('/');
            static::ensureKernelShutdown();
        }
    }

    public function testLocaleSwitchKeepsNormalLocalTarget(): void
    {
        $client = static::createClient();
        $token = $client->getContainer()->get(CsrfTokenManagerInterface::class)->getToken('locale-switch')->getValue();
        $client->request('POST', '/locale/de', ['_token' => $token, '_target' => '/news?page=2']);

        self::assertResponseRedirects('/news?page=2');
    }
}
