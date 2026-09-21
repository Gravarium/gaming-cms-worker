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
        self::assertSame(['status' => 'failed'], $sanitized['nested']);
        self::assertSame([['provider' => 'discord']], $sanitized['items']);
    }
}
