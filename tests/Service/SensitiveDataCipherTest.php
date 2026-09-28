<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SensitiveDataCipher;
use PHPUnit\Framework\TestCase;

final class SensitiveDataCipherTest extends TestCase
{
    public function testValueCanBeEncryptedAndDecrypted(): void
    {
        $cipher = new SensitiveDataCipher('test-secret');
        $plainText = 'https://discord.com/api/webhooks/123456/token_value';

        $encrypted = $cipher->encrypt($plainText);

        self::assertNotSame($plainText, $encrypted);
        self::assertSame($plainText, $cipher->decrypt($encrypted));
    }

    public function testEncryptedValueCannotBeReadWithDifferentSecret(): void
    {
        $encrypted = (new SensitiveDataCipher('first-secret'))->encrypt('sensitive-value');

        $this->expectException(\RuntimeException::class);
        (new SensitiveDataCipher('second-secret'))->decrypt($encrypted);
    }

    public function testEncryptionRejectsOversizedPlaintext(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SensitiveDataCipher('test-secret'))->encrypt(str_repeat('x', 4097));
    }

    public function testDecryptionRejectsNonCanonicalCiphertext(): void
    {
        $cipher = new SensitiveDataCipher('test-secret');
        $encrypted = $cipher->encrypt('sensitive-value');

        $this->expectException(\RuntimeException::class);
        $cipher->decrypt(' '.$encrypted);
    }

    public function testDecryptionRejectsUndersizedOrOversizedCiphertext(): void
    {
        $cipher = new SensitiveDataCipher('test-secret');

        try {
            $cipher->decrypt(base64_encode(str_repeat(chr(0), SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)));
            self::fail('An undersized authenticated payload was accepted.');
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(\RuntimeException::class);
        $cipher->decrypt(str_repeat('A', 8193));
    }
}
