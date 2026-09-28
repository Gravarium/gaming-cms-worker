<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserSessionRepository;
use App\Service\SensitiveDataCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class TwoFactorRecoveryCodeRotationTest extends WebTestCase
{
    private const MANAGE_PATH = '/account/security/2fa/recovery-codes/manage';
    private const ROTATE_PATH = '/account/security/2fa/recovery-codes/rotate';
    private const CODES_PATH = '/account/security/2fa/recovery-codes';

    private ?KernelBrowser $client = null;

    /** @var list<int> */
    private array $userIds = [];

    public function testRotationIsReachableKeepsAuthenticatorAndShowsNewCodesOnlyOnce(): void
    {
        [$client, $userId, $secret, $encryptedSecret] = $this->createVerifiedClient(
            'rotate',
            'Current-Password-42',
            ['AAAA-BBBB-CCCC', 'DDDD-EEEE-FFFF'],
        );

        $securityPage = $client->request('GET', '/account/security');
        self::assertResponseIsSuccessful();
        $passkeysPath = $this->linkHref($securityPage, 'Passkeys verwalten');
        $passkeysPage = $client->request('GET', $passkeysPath);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="'.self::MANAGE_PATH.'"]');

        $managePath = $this->linkHref($passkeysPage, 'Wiederherstellungscodes verwalten');
        self::assertSame(self::MANAGE_PATH, $managePath);
        $managePage = $client->request('GET', $managePath);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
        self::assertSelectorTextContains('body', 'Du hast noch 2 Wiederherstellungscodes');

        $entityManager = $this->em($client);
        $entityManager->clear();
        $storedBefore = $entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $storedBefore);
        $oldVersion = $storedBefore->getSecurityVersion();
        $oldHashes = $storedBefore->getRecoveryCodeHashes();

        $otherSession = new UserSession(
            $storedBefore,
            'recovery-rotation-other-'.bin2hex(random_bytes(12)),
            '127.0.0.2',
            'Other browser',
        );
        $entityManager->persist($otherSession);
        $entityManager->flush();
        $otherSessionId = $otherSession->getId();
        self::assertNotNull($otherSessionId);

        $token = (string) $managePage->filter('input[name="_token"]')->attr('value');
        $client->request('POST', self::ROTATE_PATH, [
            '_token' => $token,
            'password' => 'Current-Password-42',
        ]);
        self::assertResponseRedirects(self::CODES_PATH);
        $sessionId = $client->getRequest()->getSession()->getId();

        $codesPage = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control', ''));
        self::assertSelectorTextContains('h1', 'Wiederherstellungscodes sichern');
        self::assertSame(10, $codesPage->filter('.panel li code')->count());
        $firstNewCode = trim($codesPage->filter('.panel li code')->first()->text());
        self::assertNotSame('', $firstNewCode);

        $client->request('GET', self::CODES_PATH);
        self::assertResponseRedirects('/account/security');

        $entityManager->clear();
        $storedUser = $entityManager->find(User::class, $userId);
        $storedOtherSession = $entityManager->find(UserSession::class, $otherSessionId);
        self::assertInstanceOf(User::class, $storedUser);
        self::assertInstanceOf(UserSession::class, $storedOtherSession);
        self::assertTrue($storedUser->isTwoFactorEnabled());
        self::assertSame($encryptedSecret, $storedUser->getTwoFactorSecret());
        self::assertSame($oldVersion + 1, $storedUser->getSecurityVersion());
        self::assertSame(10, $storedUser->recoveryCodeCount());
        self::assertNotSame($oldHashes, $storedUser->getRecoveryCodeHashes());
        self::assertTrue($this->recoveryCodeMatches($firstNewCode, $storedUser->getRecoveryCodeHashes()));
        self::assertFalse($storedUser->consumeRecoveryCode('DDDD-EEEE-FFFF'));
        self::assertTrue($storedOtherSession->isRevoked());

        $currentSession = $client->getContainer()->get(UserSessionRepository::class)->findBySessionId($sessionId);
        self::assertInstanceOf(UserSession::class, $currentSession);
        self::assertFalse($currentSession->isRevoked());
        self::assertSame($storedUser->getSecurityVersion(), $currentSession->getSecurityVersion());

        $challenge = $client->request('GET', '/login/2fa');
        $challengeToken = (string) $challenge->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/login/2fa', [
            '_token' => $challengeToken,
            'code' => 'DDDD-EEEE-FFFF',
        ]);
        self::assertResponseRedirects('/login/2fa');

        $entityManager->clear();
        $storedUser = $entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $storedUser);
        self::assertSame(10, $storedUser->recoveryCodeCount());
        $rotationLogs = $entityManager->getRepository(AuditLog::class)->findBy([
            'actor' => $storedUser,
            'action' => 'security.2fa.recovery_codes_rotated',
        ]);
        self::assertCount(1, $rotationLogs);
        self::assertStringNotContainsString($firstNewCode, $rotationLogs[0]->getSummary());
        self::assertSame([], $rotationLogs[0]->getContext());
    }

    public function testWrongPasswordLeavesCodesSecretAndSessionsUnchanged(): void
    {
        [$client, $userId, , $encryptedSecret] = $this->createVerifiedClient(
            'wrong-password',
            'Current-Password-42',
            ['AAAA-BBBB-CCCC', 'DDDD-EEEE-FFFF'],
        );
        $managePage = $client->request('GET', self::MANAGE_PATH);
        self::assertResponseIsSuccessful();
        $token = (string) $managePage->filter('input[name="_token"]')->attr('value');

        $entityManager = $this->em($client);
        $entityManager->clear();
        $before = $entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $before);
        $hashes = $before->getRecoveryCodeHashes();
        $version = $before->getSecurityVersion();

        $client->request('POST', self::ROTATE_PATH, [
            '_token' => $token,
            'password' => 'Wrong-Password-42',
        ]);
        self::assertResponseRedirects(self::MANAGE_PATH);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Die Wiederherstellungscodes blieben unverändert.');

        $entityManager->clear();
        $storedUser = $entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $storedUser);
        self::assertTrue($storedUser->isTwoFactorEnabled());
        self::assertSame($encryptedSecret, $storedUser->getTwoFactorSecret());
        self::assertSame($hashes, $storedUser->getRecoveryCodeHashes());
        self::assertSame($version, $storedUser->getSecurityVersion());
        self::assertSame([], $entityManager->getRepository(AuditLog::class)->findBy([
            'actor' => $storedUser,
            'action' => 'security.2fa.recovery_codes_rotated',
        ]));

        $client->request('GET', self::CODES_PATH);
        self::assertResponseRedirects('/account/security');
    }

    public function testMissingInvalidAndMalformedCsrfDoNotRotateCodes(): void
    {
        [$client, $userId, , $encryptedSecret] = $this->createVerifiedClient(
            'csrf',
            'Current-Password-42',
            ['AAAA-BBBB-CCCC'],
        );
        $entityManager = $this->em($client);
        $entityManager->clear();
        $before = $entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $before);
        $hashes = $before->getRecoveryCodeHashes();
        $version = $before->getSecurityVersion();

        $client->request('POST', self::ROTATE_PATH, ['password' => 'Current-Password-42']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', self::ROTATE_PATH, [
            '_token' => 'invalid-token',
            'password' => 'Current-Password-42',
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', self::ROTATE_PATH, [
            '_token' => ['malformed-token'],
            'password' => 'Current-Password-42',
        ]);
        self::assertResponseStatusCodeSame(403);

        $entityManager->clear();
        $storedUser = $entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $storedUser);
        self::assertTrue($storedUser->isTwoFactorEnabled());
        self::assertSame($encryptedSecret, $storedUser->getTwoFactorSecret());
        self::assertSame($hashes, $storedUser->getRecoveryCodeHashes());
        self::assertSame($version, $storedUser->getSecurityVersion());
        self::assertSame([], $entityManager->getRepository(AuditLog::class)->findBy([
            'actor' => $storedUser,
            'action' => 'security.2fa.recovery_codes_rotated',
        ]));

        $client->request('GET', self::CODES_PATH);
        self::assertResponseRedirects('/account/security');
    }

    public function testDisabledTwoFactorCannotOpenOrSubmitRecoveryCodeRotation(): void
    {
        $client = static::createClient();
        $this->client = $client;
        $user = $this->createUser($client, 'disabled', 'Current-Password-42');
        $userId = $user->getId();
        if ($userId === null) {
            throw new \LogicException('Test user was not persisted.');
        }
        $version = $user->getSecurityVersion();
        $client->loginUser($user);

        $client->request('GET', self::MANAGE_PATH);
        self::assertResponseRedirects('/account/security');

        $securityPage = $client->request('GET', '/account/security');
        $passkeysPath = $this->linkHref($securityPage, 'Passkeys verwalten');
        $client->request('GET', $passkeysPath);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="'.self::MANAGE_PATH.'"]');

        $client->request('POST', self::ROTATE_PATH, [
            'password' => 'Current-Password-42',
        ]);
        self::assertResponseStatusCodeSame(403);

        $entityManager = $this->em($client);
        $entityManager->clear();
        $storedUser = $entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $storedUser);
        self::assertFalse($storedUser->isTwoFactorEnabled());
        self::assertSame(0, $storedUser->recoveryCodeCount());
        self::assertSame($version, $storedUser->getSecurityVersion());
        self::assertSame([], $entityManager->getRepository(AuditLog::class)->findBy([
            'actor' => $storedUser,
            'action' => 'security.2fa.recovery_codes_rotated',
        ]));
    }

    public function testRecoveryRotationPasswordAttemptsAreRateLimitedPerAccount(): void
    {
        [$client, $userId, , $encryptedSecret] = $this->createVerifiedClient(
            'rate-limit',
            'Current-Password-42',
            ['AAAA-BBBB-CCCC'],
        );
        /** @var RateLimiterFactory $factory */
        $factory = $client->getContainer()->get('limiter.two_factor_recovery_rotation');
        $limiter = $factory->create('user-'.$userId);
        $limiter->reset();

        $entityManager = $this->em($client);
        $entityManager->clear();
        $before = $entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $before);
        $hashes = $before->getRecoveryCodeHashes();
        $version = $before->getSecurityVersion();

        try {
            for ($attempt = 0; $attempt < 5; ++$attempt) {
                $managePage = $client->request('GET', self::MANAGE_PATH);
                self::assertResponseIsSuccessful();
                $token = (string) $managePage->filter('input[name="_token"]')->attr('value');
                $client->request('POST', self::ROTATE_PATH, [
                    '_token' => $token,
                    'password' => 'Wrong-Password-42',
                ]);
                self::assertResponseRedirects(self::MANAGE_PATH);
            }

            $managePage = $client->request('GET', self::MANAGE_PATH);
            $token = (string) $managePage->filter('input[name="_token"]')->attr('value');
            $client->request('POST', self::ROTATE_PATH, [
                '_token' => $token,
                'password' => 'Current-Password-42',
            ]);
            self::assertResponseRedirects(self::MANAGE_PATH);
            $client->request('GET', self::MANAGE_PATH);
            self::assertSelectorTextContains('body', 'Zu viele Versuche');

            $entityManager->clear();
            $storedUser = $entityManager->find(User::class, $userId);
            self::assertInstanceOf(User::class, $storedUser);
            self::assertTrue($storedUser->isTwoFactorEnabled());
            self::assertSame($encryptedSecret, $storedUser->getTwoFactorSecret());
            self::assertSame($hashes, $storedUser->getRecoveryCodeHashes());
            self::assertSame($version, $storedUser->getSecurityVersion());
            self::assertSame([], $entityManager->getRepository(AuditLog::class)->findBy([
                'actor' => $storedUser,
                'action' => 'security.2fa.recovery_codes_rotated',
            ]));

            $client->request('GET', self::CODES_PATH);
            self::assertResponseRedirects('/account/security');
        } finally {
            $limiter->reset();
        }
    }

    protected function tearDown(): void
    {
        if ($this->client instanceof KernelBrowser) {
            /** @var RateLimiterFactory $factory */
            $factory = $this->client->getContainer()->get('limiter.two_factor_recovery_rotation');
            $entityManager = $this->em($this->client);
            foreach ($this->userIds as $userId) {
                $factory->create('user-'.$userId)->reset();
                $entityManager->clear();
                $user = $entityManager->find(User::class, $userId);
                if (!$user instanceof User) {
                    continue;
                }
                $entityManager->createQuery('DELETE FROM App\Entity\AuditLog auditLog WHERE auditLog.actor = :user')
                    ->setParameter('user', $user)
                    ->execute();
                $entityManager->createQuery('DELETE FROM App\Entity\UserSession userSession WHERE userSession.user = :user')
                    ->setParameter('user', $user)
                    ->execute();
                $entityManager->remove($user);
                $entityManager->flush();
            }
        }

        parent::tearDown();
    }

    /**
     * @param list<string> $recoveryCodes
     * @return array{KernelBrowser, int, string, string}
     */
    private function createVerifiedClient(string $label, string $password, array $recoveryCodes): array
    {
        $client = static::createClient();
        $this->client = $client;
        $user = $this->createUser($client, $label, $password);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $cipher = $client->getContainer()->get(SensitiveDataCipher::class);
        if (!$cipher instanceof SensitiveDataCipher) {
            throw new \LogicException('Sensitive data cipher service is unavailable.');
        }
        $encryptedSecret = $cipher->encrypt($secret);
        $user->enableTwoFactor(
            $encryptedSecret,
            array_map(static fn (string $code): string => password_hash($code, PASSWORD_DEFAULT), $recoveryCodes),
        );
        $this->em($client)->flush();
        $userId = $user->getId();
        if ($userId === null) {
            throw new \LogicException('Test user was not persisted.');
        }

        $client->loginUser($user);
        $challenge = $client->request('GET', '/login/2fa');
        $token = (string) $challenge->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/login/2fa', [
            '_token' => $token,
            'code' => $this->totpCode($secret),
        ]);
        self::assertResponseRedirects('/account');

        return [$client, $userId, $secret, $encryptedSecret];
    }

    private function createUser(KernelBrowser $client, string $label, string $password): User
    {
        $user = (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(8)).'@example.test')
            ->setDisplayName('Recovery rotation '.$label)
            ->verifyEmail();
        $user->setPassword($this->hasher($client)->hashPassword($user, $password));
        $entityManager = $this->em($client);
        $entityManager->persist($user);
        $entityManager->flush();

        $userId = $user->getId();
        if ($userId === null) {
            throw new \LogicException('Test user was not persisted.');
        }
        $this->userIds[] = $userId;

        return $user;
    }

    private function linkHref(Crawler $page, string $label): string
    {
        $href = $page->selectLink($label)->attr('href');
        if ($href === null || $href === '') {
            throw new \LogicException('Expected account navigation link was not rendered.');
        }

        return $href;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }

    private function hasher(KernelBrowser $client): UserPasswordHasherInterface
    {
        return $client->getContainer()->get(UserPasswordHasherInterface::class);
    }

    /** @param list<string> $hashes */
    private function recoveryCodeMatches(string $code, array $hashes): bool
    {
        foreach ($hashes as $hash) {
            if (password_verify($code, $hash)) {
                return true;
            }
        }

        return false;
    }

    private function totpCode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $key = '';

        foreach (str_split(strtoupper($secret)) as $character) {
            $position = strpos($alphabet, $character);
            if ($position === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $position;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $key .= chr(($buffer >> $bits) & 255);
                $buffer = $bits === 0 ? 0 : $buffer & ((1 << $bits) - 1);
            }
        }

        $counter = intdiv(time(), 30);
        $binaryCounter = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $number = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
