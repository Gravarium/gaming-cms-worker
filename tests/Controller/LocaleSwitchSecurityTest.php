<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\SiteSettings;
use App\Internationalization\LocalePolicy;
use App\Repository\SiteSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;

final class LocaleSwitchSecurityTest extends WebTestCase
{
    public function testLocaleSwitchRejectsCsrfAndDisabledLocalesAndKeepsRedirectLocal(): void
    {
        $client = static::createClient();
        $repository = $client->getContainer()->get(SiteSettingsRepository::class);
        $knownSettingsIds = [];
        foreach ($repository->findAll() as $storedSettings) {
            $id = $storedSettings->getId();
            if ($id !== null) {
                $knownSettingsIds[] = $id;
            }
        }
        $original = $repository->current();
        $originalId = $original->getId();
        $originalLocaleState = $this->localeSnapshot($original);

        try {
            $original
                ->setDefaultLocale('de')
                ->setEnabledLocales(['de', 'en']);
            if ($originalId === null) {
                $this->em($client)->persist($original);
            }
            $this->em($client)->flush();

            $token = $this->renderedLocaleToken($client);

            $client->request('POST', '/locale/de', ['_target' => '/']);
            self::assertResponseStatusCodeSame(403);
            $this->assertNoLocaleCookie($client);

            $client->request('POST', '/locale/de', [
                '_token' => 'invalid-locale-switch-token',
                '_target' => '/',
            ]);
            self::assertResponseStatusCodeSame(403);
            $this->assertNoLocaleCookie($client);

            $client->request('POST', '/locale/fr', [
                '_token' => $token,
                '_target' => '/',
            ]);
            self::assertResponseStatusCodeSame(404);
            $this->assertNoLocaleCookie($client);

            $token = $this->renderedLocaleToken($client);
            $client->request('POST', '/locale/en', [
                '_token' => $token,
                '_target' => 'https://attacker.invalid/redirect',
            ]);

            self::assertResponseRedirects('/');
            $localeCookie = $this->localeCookie($client);
            self::assertSame('en', $localeCookie->getValue());
            self::assertTrue($localeCookie->isHttpOnly());
            self::assertSame(Cookie::SAMESITE_LAX, $localeCookie->getSameSite());
        } finally {
            $this->restoreSettings($client, $knownSettingsIds, $originalId, $originalLocaleState);
        }
    }

    private function renderedLocaleToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('form.locale-switcher input[name="_token"]')->attr('value');
        self::assertNotSame('', $token);

        return $token;
    }

    private function assertNoLocaleCookie(KernelBrowser $client): void
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            self::assertNotSame(LocalePolicy::COOKIE, $cookie->getName());
        }
    }

    private function localeCookie(KernelBrowser $client): Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === LocalePolicy::COOKIE) {
                return $cookie;
            }
        }

        self::fail('The locale switch did not set the locale cookie.');
    }

    /**
     * @return array{defaultLocale:string,enabledLocales:list<string>}
     */
    private function localeSnapshot(SiteSettings $settings): array
    {
        return [
            'defaultLocale' => $settings->getDefaultLocale(),
            'enabledLocales' => $settings->getEnabledLocales(),
        ];
    }

    /**
     * @param list<int> $knownSettingsIds
     * @param array{defaultLocale:string,enabledLocales:list<string>} $originalLocaleState
     */
    private function restoreSettings(KernelBrowser $client, array $knownSettingsIds, ?int $originalId, array $originalLocaleState): void
    {
        $entityManager = $this->em($client);
        $entityManager->clear();
        $repository = $client->getContainer()->get(SiteSettingsRepository::class);

        foreach ($repository->findAll() as $settings) {
            $id = $settings->getId();
            if ($id !== null && !in_array($id, $knownSettingsIds, true)) {
                $entityManager->remove($settings);
            } elseif ($id === $originalId) {
                $settings
                    ->setDefaultLocale($originalLocaleState['defaultLocale'])
                    ->setEnabledLocales($originalLocaleState['enabledLocales']);
            }
        }

        $entityManager->flush();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
