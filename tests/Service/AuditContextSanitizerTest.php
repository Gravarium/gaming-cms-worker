<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AuditContextSanitizer;
use PHPUnit\Framework\TestCase;

final class AuditContextSanitizerTest extends TestCase
{
    public function testRedactsNestedSecretFieldsWithoutRemovingUsefulIdentifiers(): void
    {
        $sanitized = (new AuditContextSanitizer())->sanitize([
            'userId' => 42,
            'credentialId' => 'public-id',
            'accessToken' => 'secret-token',
            'nested' => [
                'plain_password' => 'secret-password',
                'api_key' => 'secret-key',
                'safe' => 'visible',
            ],
        ]);

        self::assertSame(42, $sanitized['userId']);
        self::assertSame('public-id', $sanitized['credentialId']);
        self::assertSame('[REDACTED]', $sanitized['accessToken']);
        self::assertSame('[REDACTED]', $sanitized['nested']['plain_password']);
        self::assertSame('[REDACTED]', $sanitized['nested']['api_key']);
        self::assertSame('visible', $sanitized['nested']['safe']);
    }

    public function testVeryDeepContextDoesNotRetainDeepSecrets(): void
    {
        $value = ['secret' => 'hidden'];
        for ($i = 0; $i < 20; ++$i) {
            $value = ['nested' => $value];
        }

        $sanitized = (new AuditContextSanitizer())->sanitize($value);
        self::assertStringNotContainsString('hidden', json_encode($sanitized, JSON_THROW_ON_ERROR));
    }
}
