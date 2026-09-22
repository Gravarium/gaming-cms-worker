<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AuditContextSanitizer;
use PHPUnit\Framework\TestCase;

final class AuditContextSanitizerTest extends TestCase
{
    public function testRemovesSensitiveKeysRecursivelyWithoutDroppingSafeDiagnostics(): void
    {
        $sanitized = (new AuditContextSanitizer())->sanitize([
            'targetKey' => 'media-primary',
            'plainPassword' => 'never-log-me',
            'apiKey' => 'api-secret',
            'apikey' => 'compact-api-secret',
            'x-api-key' => 'header-api-secret',
            'private_key' => 'private-secret',
            'masterKey' => 'master-secret',
            'pwd' => 'short-password-secret',
            'nested' => [
                'access_token' => 'secret-token',
                'credentialId' => 'credential-secret',
                'status' => 'failed',
            ],
            'items' => [
                ['webhookUrl' => 'https://secret.invalid/hook', 'provider' => 'discord'],
            ],
        ]);

        self::assertSame('media-primary', $sanitized['targetKey']);
        self::assertArrayNotHasKey('plainPassword', $sanitized);
        self::assertArrayNotHasKey('apiKey', $sanitized);
        self::assertArrayNotHasKey('apikey', $sanitized);
        self::assertArrayNotHasKey('x-api-key', $sanitized);
        self::assertArrayNotHasKey('private_key', $sanitized);
        self::assertArrayNotHasKey('masterKey', $sanitized);
        self::assertArrayNotHasKey('pwd', $sanitized);
        self::assertSame(['status' => 'failed'], $sanitized['nested']);
        self::assertSame([['provider' => 'discord']], $sanitized['items']);
    }
}
