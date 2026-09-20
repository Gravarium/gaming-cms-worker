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
}
