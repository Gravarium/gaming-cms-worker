<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AccountSessionRevocationSecurityTest extends WebTestCase
{
    public function testAnotherAccountCannotRevokeTheOwnerSession(): void
    {
        $client = static::createClient();
        $owner = $this->createUser($client, 'session-owner');
        $targetSessionId = $this->createTrackedSession($client, $owner, 'owner-device');
        $attacker = $this->createUser($client, 'session-attacker');
        $client->loginUser($attacker);

        $client->request('POST', '/account/security/sessions/'.$targetSessionId.'/revoke');

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->findSession($client, $targetSessionId)->isRevoked());
    }

    public function testOwnerCanRevokeAnotherSessionWithItsRenderedCsrfToken(): void
    {
        $client = static::createClient();
        $owner = $this->createUser($client, 'session-revoke-owner');
        $targetSessionId = $this->createTrackedSession($client, $owner, 'revoke-target');
        $client->loginUser($owner);

        $crawler = $client->request('GET', '/account/security');
        self::assertResponseIsSuccessful();
        $currentSessionId = $client->getRequest()->getSession()->getId();
        $currentSession = $client->getContainer()
            ->get(UserSessionRepository::class)
            ->findBySessionId($currentSessionId);
        self::assertInstanceOf(UserSession::class, $currentSession);
        $currentSessionRecordId = $currentSession->getId();
        if ($currentSessionRecordId === null) {
            throw new LogicException('The current session fixture must have an identifier.');
        }

        $token = $this->renderedSessionToken($crawler, $targetSessionId);
        $client->request('POST', '/account/security/sessions/'.$targetSessionId.'/revoke', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/account/security');
        self::assertTrue($this->findSession($client, $targetSessionId)->isRevoked());
        self::assertFalse($this->findSession($client, $currentSessionRecordId)->isRevoked());
    }

    public function testRevokingCurrentSessionLogsBrowserOut(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'current-session-revoke');
        $client->loginUser($user);

        $crawler = $client->request('GET', '/account/security');
        self::assertResponseIsSuccessful();
        $currentSessionHash = $client->getRequest()->getSession()->getId();
        $currentSession = $client->getContainer()
            ->get(UserSessionRepository::class)
            ->findBySessionId($currentSessionHash);
        self::assertInstanceOf(UserSession::class, $currentSession);
        $currentSessionId = $currentSession->getId();
        if ($currentSessionId === null) {
            throw new LogicException('The current session fixture must have an identifier.');
        }

        $token = $this->renderedSessionToken($crawler, $currentSessionId);
        $client->request('POST', '/account/security/sessions/'.$currentSessionId.'/revoke', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/login');
        $client->request('GET', '/account/security');
        self::assertResponseRedirects('/login');
        self::assertTrue($this->findSession($client, $currentSessionId)->isRevoked());
    }

    private function createUser(KernelBrowser $client, string $label): User
    {
        $user = (new User())
            ->setEmail('session-revocation-'.$label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Session revocation '.$label)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createTrackedSession(KernelBrowser $client, User $user, string $label): int
    {
        $session = new UserSession(
            $user,
            'session-revocation-'.$label.'-'.bin2hex(random_bytes(12)),
            '203.0.113.11',
            'Session revocation test browser',
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($session);
        $entityManager->flush();
        $id = $session->getId();
        if ($id === null) {
            throw new LogicException('The target session fixture must have an identifier.');
        }

        return $id;
    }

    private function findSession(KernelBrowser $client, int $id): UserSession
    {
        $session = $client->getContainer()->get(EntityManagerInterface::class)->find(UserSession::class, $id);
        self::assertInstanceOf(UserSession::class, $session);

        return $session;
    }

    private function renderedSessionToken(Crawler $crawler, int $sessionId): string
    {
        $selector = sprintf(
            'form[action="/account/security/sessions/%d/revoke"] input[name="_token"]',
            $sessionId,
        );
        $token = $crawler->filter($selector)->attr('value');
        if (!is_string($token) || $token === '') {
            throw new LogicException('The account page must render a CSRF token for the selected session.');
        }

        return $token;
    }
}
