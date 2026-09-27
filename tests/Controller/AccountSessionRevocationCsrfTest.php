<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\UserSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccountSessionRevocationCsrfTest extends WebTestCase
{
    public function testOwnedSessionRequiresValidCsrfTokenBeforeRevocation(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('session-csrf-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Session CSRF test')
            ->verifyEmail();
        $entityManager->persist($user);
        $entityManager->flush();

        $session = new UserSession($user, 'other-'.bin2hex(random_bytes(16)), '127.0.0.2', 'Other browser');
        $entityManager->persist($session);
        $entityManager->flush();
        $sessionId = $session->getId();
        self::assertNotNull($sessionId);

        $client->loginUser($user);
        $crawler = $client->request('GET', '/account/security');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/account/security/sessions/'.$sessionId.'/revoke"]');
        self::assertCount(1, $form);
        $token = $form->filter('input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $path = '/account/security/sessions/'.$sessionId.'/revoke';
        $client->request('POST', $path);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->session($client, $sessionId)->isRevoked());

        $client->request('POST', $path, ['_token' => 'forged-session-revocation-token']);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->session($client, $sessionId)->isRevoked());

        $client->request('POST', $path, ['_token' => $token]);
        self::assertResponseRedirects('/account/security');
        self::assertTrue($this->session($client, $sessionId)->isRevoked());
    }

    private function session(KernelBrowser $client, int $sessionId): UserSession
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $session = $entityManager->find(UserSession::class, $sessionId);
        self::assertInstanceOf(UserSession::class, $session);

        return $session;
    }
}
