<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

final class LoginThrottlingGlobalIpSecurityTest extends WebTestCase
{
    private const GLOBAL_ATTEMPT_LIMIT = 25;
    private const ATTEMPTS_TO_REACH_BLOCK = self::GLOBAL_ATTEMPT_LIMIT + 1;

    public function testGlobalThrottleStopsAttemptsAcrossDistinctUsernames(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->setServerParameter('REMOTE_ADDR', '192.0.2.'.random_int(1, 254));
        $nonce = bin2hex(random_bytes(8));
        $throttled = false;

        for ($attempt = 0; $attempt < self::ATTEMPTS_TO_REACH_BLOCK; ++$attempt) {
            $email = 'unknown-throttle-'.$nonce.'-'.$attempt.'@example.test';
            $crawler = $client->request('GET', '/login');
            self::assertResponseIsSuccessful();

            $form = $crawler->selectButton('Anmelden')->form([
                '_username' => $email,
                '_password' => 'Wrong-Password-42',
            ]);
            $client->submit($form);
            self::assertResponseRedirects('/login');

            $session = $client->getSession();
            self::assertNotNull($session);
            $authenticationError = $session->get(SecurityRequestAttributes::AUTHENTICATION_ERROR);

            if ($authenticationError instanceof TooManyLoginAttemptsAuthenticationException) {
                self::assertSame(self::GLOBAL_ATTEMPT_LIMIT, $attempt, sprintf('The global limit should allow the configured %d attempts.', self::GLOBAL_ATTEMPT_LIMIT));
                $throttled = true;
                break;
            }

            self::assertInstanceOf(AuthenticationException::class, $authenticationError);
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.alert', 'E-Mail-Adresse oder Passwort ist falsch.');
        }

        self::assertTrue($throttled, 'The global per-IP login throttle should reject attempts across distinct usernames.');

        $client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }
}
