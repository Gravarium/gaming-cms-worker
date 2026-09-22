<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\SiteSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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
        $token = $this->localeToken($client);

        $client->request('POST', '/locale/de', [
            '_token' => $token,
            '_target' => '/\\evil.example.test',
        ]);

        self::assertResponseRedirects('/');
    }

    public function testLocaleSwitchRejectsEncodedPathSeparatorRedirectTarget(): void
    {
        $client = static::createClient();
        $token = $this->localeToken($client);

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

    private function localeToken(KernelBrowser $client): string
    {
        $settings = $client->getContainer()->get(SiteSettingsRepository::class)->current();
        $previousLocales = $settings->getEnabledLocales();
        $settings->setEnabledLocales(['de', 'en']);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($settings);
        $entityManager->flush();

        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('form[data-locale-switcher] input[name="_token"]')->attr('value');

        $settings = $client->getContainer()->get(SiteSettingsRepository::class)->current();
        $settings->setEnabledLocales($previousLocales);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($settings);
        $entityManager->flush();

        return $token;
    }
}
