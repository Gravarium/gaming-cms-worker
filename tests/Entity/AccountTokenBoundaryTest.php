<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AccountToken;
use PHPUnit\Framework\TestCase;

final class AccountTokenBoundaryTest extends TestCase
{
    public function testAcceptsOnlyTheDeclaredPurposesAndAValidSha256Digest(): void
    {
        $digest = hash('sha256', 'synthetic-only-account-token');

        self::assertSame(64, strlen($digest));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $digest);

        foreach ([AccountToken::PURPOSE_EMAIL_VERIFICATION, AccountToken::PURPOSE_PASSWORD_RESET] as $purpose) {
            $token = (new AccountToken())
                ->setPurpose($purpose)
                ->setTokenHash($digest);

            self::assertSame($purpose, $token->getPurpose());
            self::assertSame($digest, $token->getTokenHash());
        }
    }

    public function testRejectedPurposesLeaveTheStoredPurposeUnchanged(): void
    {
        $token = (new AccountToken())->setPurpose(AccountToken::PURPOSE_EMAIL_VERIFICATION);

        foreach (['', 'email', 'email_verification ', 'EMAIL_VERIFICATION', str_repeat('x', 41)] as $purpose) {
            $this->assertRejected(static function () use ($token, $purpose): void {
                $token->setPurpose($purpose);
            });

            self::assertSame(AccountToken::PURPOSE_EMAIL_VERIFICATION, $token->getPurpose());
        }
    }

    public function testRejectedDigestsLeaveTheStoredHashUnchanged(): void
    {
        $storedDigest = hash('sha256', 'synthetic-stored-token');
        $token = (new AccountToken())->setTokenHash($storedDigest);

        foreach ([
            '',
            str_repeat('a', 63),
            str_repeat('a', 65),
            str_repeat('g', 64),
            strtoupper(hash('sha256', 'synthetic-uppercase-token')),
            'synthetic-plain-token',
        ] as $digest) {
            $this->assertRejected(static function () use ($token, $digest): void {
                $token->setTokenHash($digest);
            });

            self::assertSame($storedDigest, $token->getTokenHash());
        }
    }

    /** @param \Closure(): mixed $operation */
    private function assertRejected(\Closure $operation): void
    {
        try {
            $operation();
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);

            return;
        }

        self::fail('An invalid account token value must be rejected.');
    }
}
