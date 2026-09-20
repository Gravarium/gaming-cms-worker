<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TotpAuthenticator;
use PHPUnit\Framework\TestCase;

final class TotpAuthenticatorTest extends TestCase
{
    public function testKnownRfcVectorIsAcceptedAsSixDigitCode(): void
    {
        $authenticator = new TotpAuthenticator();
        self::assertTrue($authenticator->verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59));
        self::assertFalse($authenticator->verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '000000', 59));
    }

    public function testGeneratedRecoveryCodesAreUnique(): void
    {
        $codes = (new TotpAuthenticator())->generateRecoveryCodes();
        self::assertCount(10, $codes);
        self::assertCount(10, array_unique($codes));
        foreach ($codes as $code) { self::assertMatchesRegularExpression('/^[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/', $code); }
    }
}
