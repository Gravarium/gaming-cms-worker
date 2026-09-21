<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserRepository;
use App\Repository\UserSessionRepository;
use App\Service\SensitiveDataCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TwoFactorSecurityTest extends WebTestCase
{
    public function testTwoFactorGateRequiresChallengeAndRecoveryCodeIsOneTime(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'challenge');
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $recoveryCode = 'ABCD-EF12-3456';
        $user->enableTwoFactor(
            $client->getContainer()->get(SensitiveDataCipher::class)->encrypt($secret),
            [password_hash($recoveryCode, PASSWORD_DEFAULT)],
        );
        $this->em($client)->flush();
        $userId = $user->getId();
        self::assertNotNull($userId);
        $oldVersion = $user->getSecurityVersion();
        $client->loginUser($user);

        $client->request('GET', '/account/security');
        self::assertResponseRedirects('/login/2fa');

        $crawler = $client->request('GET', '/login/2fa');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/login/2fa', ['_token' => $token, 'code' => $recoveryCode]);
        self::assertResponseRedirects('/account');

        $stored = $client->getContainer()->get(UserRepository::class)->find($userId);
        self::assertInstanceOf(User::class, $stored);
        self::assertSame(0, $stored->recoveryCodeCount());
        self::assertSame($oldVersion + 1, $stored->getSecurityVersion());

        $crawler = $client->request('GET', '/login/2fa');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/login/2fa', ['_token' => $token, 'code' => $recoveryCode]);
        self::assertResponseRedirects('/login/2fa');
    }

    public function testTwoFactorChallengeRejectsMissingCsrf(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'csrf');
        $user->enableTwoFactor(
            $client->getContainer()->get(SensitiveDataCipher::class)->encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            [password_hash('AAAA-BBBB-CCCC', PASSWORD_DEFAULT)],
        );
        $this->em($client)->flush();
        $userId = $user->getId();
        self::assertNotNull($userId);
        $client->loginUser($user);

        $client->request('POST', '/login/2fa', ['code' => 'AAAA-BBBB-CCCC']);
        self::assertResponseStatusCodeSame(403);
        $stored = $client->getContainer()->get(UserRepository::class)->find($userId);
        self::assertInstanceOf(User::class, $stored);
        self::assertSame(1, $stored->recoveryCodeCount());
    }

    public function testTwoFactorRateLimitSurvivesSessionCounterReset(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'rate-limit');
        $recoveryCode = 'RATE-LIMIT-1234';
        $user->enableTwoFactor(
            $client->getContainer()->get(SensitiveDataCipher::class)->encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            [password_hash($recoveryCode, PASSWORD_DEFAULT)],
        );
        $this->em($client)->flush();
        $userId = $user->getId();
        self::assertNotNull($userId);
        $client->loginUser($user);

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $crawler = $client->request('GET', '/login/2fa');
            $client->request('POST', '/login/2fa', [
                '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'),
                'code' => '000000',
            ]);
            self::assertResponseRedirects('/login/2fa');
            $client->getRequest()->getSession()->remove('two_factor_failures');
            $client->getRequest()->getSession()->remove('two_factor_locked_until');
        }

        $crawler = $client->request('GET', '/login/2fa');
        $client->request('POST', '/login/2fa', [
            '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'),
            'code' => $recoveryCode,
        ]);
        self::assertResponseRedirects('/login/2fa');

        $this->em($client)->clear();
        $stored = $this->em($client)->find(User::class, $userId);
        self::assertInstanceOf(User::class, $stored);
        self::assertSame(1, $stored->recoveryCodeCount());
    }

    public function testEnablingTwoFactorRevokesOtherSessionsAndKeepsCurrentSessionValid(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'enable');
        $otherSession = new UserSession($user, 'other-'.bin2hex(random_bytes(16)), '127.0.0.2', 'Other browser');
        $this->em($client)->persist($otherSession);
        $this->em($client)->flush();
        $userId = $user->getId();
        $otherSessionId = $otherSession->getId();
        self::assertNotNull($userId);
        self::assertNotNull($otherSessionId);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/account/security/2fa/setup');
        self::assertResponseIsSuccessful();
        $secret = trim($crawler->filter('ol code')->eq(0)->text());
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $oldVersion = $user->getSecurityVersion();

        $client->request('POST', '/account/security/2fa/setup', [
            '_token' => $token,
            'code' => $this->totpCode($secret),
        ]);
        self::assertResponseRedirects('/account/security/2fa/recovery-codes');

        $sessionId = $client->getRequest()->getSession()->getId();
        $this->em($client)->clear();
        $storedUser = $this->em($client)->find(User::class, $userId);
        $storedOther = $this->em($client)->find(UserSession::class, $otherSessionId);
        $current = $client->getContainer()->get(UserSessionRepository::class)->findBySessionId($sessionId);

        self::assertInstanceOf(User::class, $storedUser);
        self::assertInstanceOf(UserSession::class, $storedOther);
        self::assertInstanceOf(UserSession::class, $current);
        self::assertTrue($storedUser->isTwoFactorEnabled());
        self::assertSame($oldVersion + 1, $storedUser->getSecurityVersion());
        self::assertTrue($storedOther->isRevoked());
        self::assertFalse($current->isRevoked());
        self::assertSame($storedUser->getSecurityVersion(), $current->getSecurityVersion());
    }

    public function testDisablingTwoFactorRequiresCurrentPasswordAndRevokesOtherSessions(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'disable', 'Current-Password-42');
        $recoveryCode = 'FACE-CAFE-1234';
        $user->enableTwoFactor(
            $client->getContainer()->get(SensitiveDataCipher::class)->encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            [password_hash($recoveryCode, PASSWORD_DEFAULT)],
        );
        $this->em($client)->flush();
        $userId = $user->getId();
        self::assertNotNull($userId);
        $client->loginUser($user);

        $crawler = $client->request('GET', '/login/2fa');
        $client->request('POST', '/login/2fa', [
            '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'),
            'code' => $recoveryCode,
        ]);
        self::assertResponseRedirects('/account');

        $managedUser = $client->getContainer()->get(UserRepository::class)->find($userId);
        self::assertInstanceOf(User::class, $managedUser);
        $otherSession = new UserSession($managedUser, 'disable-other-'.bin2hex(random_bytes(16)), '127.0.0.3', 'Other browser');
        $this->em($client)->persist($otherSession);
        $this->em($client)->flush();
        $otherSessionId = $otherSession->getId();
        self::assertNotNull($otherSessionId);

        $crawler = $client->request('GET', '/account/security');
        $token = (string) $crawler->filter('form[action="/account/security/2fa/disable"] input[name="_token"]')->attr('value');
        $client->request('POST', '/account/security/2fa/disable', ['_token' => $token, 'password' => 'Wrong-Password-42']);
        self::assertResponseRedirects('/account/security');
        $storedUser = $client->getContainer()->get(UserRepository::class)->find($userId);
        $storedOther = $client->getContainer()->get(UserSessionRepository::class)->find($otherSessionId);
        self::assertInstanceOf(User::class, $storedUser);
        self::assertInstanceOf(UserSession::class, $storedOther);
        self::assertTrue($storedUser->isTwoFactorEnabled());
        self::assertFalse($storedOther->isRevoked());

        $crawler = $client->request('GET', '/account/security');
        $token = (string) $crawler->filter('form[action="/account/security/2fa/disable"] input[name="_token"]')->attr('value');
        $oldVersion = $storedUser->getSecurityVersion();
        $client->request('POST', '/account/security/2fa/disable', ['_token' => $token, 'password' => 'Current-Password-42']);
        self::assertResponseRedirects('/account/security');

        $this->em($client)->clear();
        $storedUser = $this->em($client)->find(User::class, $userId);
        $storedOther = $this->em($client)->find(UserSession::class, $otherSessionId);
        self::assertInstanceOf(User::class, $storedUser);
        self::assertInstanceOf(UserSession::class, $storedOther);
        self::assertFalse($storedUser->isTwoFactorEnabled());
        self::assertSame($oldVersion + 1, $storedUser->getSecurityVersion());
        self::assertTrue($storedOther->isRevoked());
    }

    private function createUser(KernelBrowser $client, string $label, string $password = 'Unused-Password-42'): User
    {
        $user = (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('2FA '.$label)
            ->verifyEmail();
        $user->setPassword($this->hasher($client)->hashPassword($user, $password));
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }

    private function hasher(KernelBrowser $client): UserPasswordHasherInterface
    {
        return $client->getContainer()->get(UserPasswordHasherInterface::class);
    }

    private function totpCode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $key = '';

        foreach (str_split(strtoupper($secret)) as $character) {
            $position = strpos($alphabet, $character);
            if ($position === false) { continue; }
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
