<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AccountToken;
use App\Entity\User;
use App\Service\AccountTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccountTokenManagerTest extends WebTestCase
{
    public function testIssueProducesFixedFormatAndResolvesAllowedPurpose(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('token-manager-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Token Manager Test')
            ->setPassword('not-used-in-this-test');
        $entityManager->persist($user);
        $entityManager->flush();

        $manager = $client->getContainer()->get(AccountTokenManager::class);
        [$token, $plainToken] = $manager->issue(
            $user,
            AccountToken::PURPOSE_PASSWORD_RESET,
            new \DateInterval('PT1H'),
        );
        $entityManager->flush();

        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $plainToken);
        self::assertSame(hash('sha256', $plainToken), $token->getTokenHash());
        self::assertNotNull($manager->resolve($plainToken, AccountToken::PURPOSE_PASSWORD_RESET));
    }

    public function testIssueRejectsUnsupportedPurposeBeforePersistence(): void
    {
        $client = static::createClient();
        $manager = $client->getContainer()->get(AccountTokenManager::class);

        $this->expectException(\InvalidArgumentException::class);
        $manager->issue(new User(), 'arbitrary-purpose', new \DateInterval('PT1H'));
    }

    public function testIssueRejectsNonPositiveLifetimeBeforePersistence(): void
    {
        $client = static::createClient();
        $manager = $client->getContainer()->get(AccountTokenManager::class);

        $this->expectException(\InvalidArgumentException::class);
        $manager->issue(new User(), AccountToken::PURPOSE_PASSWORD_RESET, new \DateInterval('PT0S'));
    }

    public function testIssueRejectsOverlongLifetimeBeforePersistence(): void
    {
        $client = static::createClient();
        $manager = $client->getContainer()->get(AccountTokenManager::class);

        $this->expectException(\InvalidArgumentException::class);
        $manager->issue(new User(), AccountToken::PURPOSE_EMAIL_VERIFICATION, new \DateInterval('P8D'));
    }

    public function testResolveAndConsumeRejectMalformedInputsWithoutRepositoryLookup(): void
    {
        $client = static::createClient();
        $manager = $client->getContainer()->get(AccountTokenManager::class);

        self::assertNull($manager->resolve('too-short', AccountToken::PURPOSE_PASSWORD_RESET));
        self::assertNull($manager->consume(str_repeat('!', 43), AccountToken::PURPOSE_PASSWORD_RESET));
        self::assertNull($manager->resolve(str_repeat('A', 43), 'arbitrary-purpose'));
    }
}
