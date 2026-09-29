<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TotpAuthenticator;
use PHPUnit\Framework\TestCase;

final class TotpAuthenticatorTest extends TestCase
{
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testKnownRfcVectorIsAcceptedAsSixDigitCode(): void
    {
        $authenticator = new TotpAuthenticator();

        self::assertTrue($authenticator->verify(self::RFC_SECRET, '287082', 59));
        self::assertTrue($authenticator->verify(strtolower(self::RFC_SECRET), ' 287082 ', 59));
        self::assertFalse($authenticator->verify(self::RFC_SECRET, '000000', 59));
        self::assertFalse($authenticator->verify(self::RFC_SECRET, 'abc287082', 59));
        self::assertFalse($authenticator->verify(self::RFC_SECRET, '287 082', 59));
        self::assertFalse($authenticator->verify(self::RFC_SECRET, '287082', -1));
    }

    public function testGeneratedSecretHasTheExpectedBoundedFormat(): void
    {
        $secret = (new TotpAuthenticator())->generateSecret();

        self::assertMatchesRegularExpression('/\A[A-Z2-7]{32}\z/', $secret);
    }

    public function testSecretGenerationRejectsUnsafeSizes(): void
    {
        $authenticator = new TotpAuthenticator();

        try {
            $authenticator->generateSecret(15);
            self::fail('A short TOTP secret was accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(\InvalidArgumentException::class);
        $authenticator->generateSecret(65);
    }

    public function testGeneratedRecoveryCodesAreUniqueAndBounded(): void
    {
        $codes = (new TotpAuthenticator())->generateRecoveryCodes();

        self::assertCount(10, $codes);
        self::assertCount(10, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/\A[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}\z/', $code);
        }
    }

    public function testRecoveryCodeGenerationRejectsUnsafeCounts(): void
    {
        $authenticator = new TotpAuthenticator();

        try {
            $authenticator->generateRecoveryCodes(0);
            self::fail('Zero recovery codes were accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(\InvalidArgumentException::class);
        $authenticator->generateRecoveryCodes(21);
    }

    public function testProvisioningUriUsesCanonicalSecretAndRejectsMalformedSecret(): void
    {
        $authenticator = new TotpAuthenticator();

        $uri = $authenticator->provisioningUri(strtolower(self::RFC_SECRET), 'player@example.test', 'Gaming CMS');
        self::assertStringContainsString('secret='.self::RFC_SECRET, $uri);
        self::assertStringContainsString('issuer=Gaming%20CMS', $uri);

        $this->expectException(\InvalidArgumentException::class);
        $authenticator->provisioningUri('not-a-secret', 'player@example.test', 'Gaming CMS');
    }
}
