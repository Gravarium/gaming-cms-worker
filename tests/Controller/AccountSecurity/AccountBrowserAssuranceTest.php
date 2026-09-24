<?php

declare(strict_types=1);

namespace App\Tests\Controller\AccountSecurity;

use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccountBrowserAssuranceTest extends WebTestCase
{
    public function testRevokedTrackedSessionIsRejectedOnNextRequest(): void
    {
        $client = static::createClient();
        $user = $this->user($client, 'revoked');
        $client->loginUser($user);

        $client->request('GET', '/account/security');
        self::assertResponseIsSuccessful();
        $session = $this->trackedSession($client);
        $session->revoke();
        $this->em($client)->flush();

        $client->request('GET', '/account/security');

        self::assertResponseRedirects('/login');
    }

    public function testOwnSessionRevokeWithoutCsrfDoesNotMutateSession(): void
    {
        $client = static::createClient();
        $user = $this->user($client, 'csrf');
        $client->loginUser($user);

        $client->request('GET', '/account/security');
        self::assertResponseIsSuccessful();
        $session = $this->trackedSession($client);
        $sessionId = $session->getId();
        self::assertNotNull($sessionId);

        $client->request('POST', '/account/security/sessions/'.$sessionId.'/revoke');

        self::assertResponseStatusCodeSame(403);
        $this->em($client)->clear();
        $stored = $this->em($client)->find(UserSession::class, $sessionId);
        self::assertInstanceOf(UserSession::class, $stored);
        self::assertFalse($stored->isRevoked());
    }

    private function user(KernelBrowser $client, string $label): User
    {
        $user = (new User())
            ->setEmail('account-browser-'.$label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Account browser '.$label)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function trackedSession(KernelBrowser $client): UserSession
    {
        $sessionId = $client->getRequest()->getSession()->getId();
        $session = $client->getContainer()->get(UserSessionRepository::class)->findBySessionId($sessionId);
        self::assertInstanceOf(UserSession::class, $session);

        return $session;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
