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

    public function testOmitsUnsupportedValuesAndBoundsDiagnostics(): void
    {
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);

        try {
            $sanitized = (new AuditContextSanitizer())->sanitize([
                'object' => new \stdClass(),
                'resource' => $resource,
                'nullValue' => null,
                'floatValue' => 1.5,
                'binary' => "safe\0hidden",
                'long' => str_repeat('x', 5000),
                'many' => array_fill(0, 150, 'safe'),
                'nested' => [
                    'safe' => str_repeat('y', 5000),
                    'object' => new \stdClass(),
                ],
            ]);
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }

        self::assertArrayNotHasKey('object', $sanitized);
        self::assertArrayNotHasKey('resource', $sanitized);
        self::assertArrayNotHasKey('binary', $sanitized);
        self::assertNull($sanitized['nullValue']);
        self::assertSame(1.5, $sanitized['floatValue']);
        self::assertSame(4096, mb_strlen($sanitized['long'], 'UTF-8'));
        self::assertCount(100, $sanitized['many']);
        self::assertSame(4096, mb_strlen($sanitized['nested']['safe'], 'UTF-8'));
        self::assertArrayNotHasKey('object', $sanitized['nested']);
    }

    public function testNestedArraysHaveAStrictDepthLimit(): void
    {
        $nested = ['leaf' => 'safe'];
        for ($index = 0; $index < 12; ++$index) {
            $nested = ['next' => $nested];
        }

        $sanitized = (new AuditContextSanitizer())->sanitize(['nested' => $nested]);

        self::assertLessThanOrEqual(8, $this->arrayDepth($sanitized['nested']));
    }

    private function arrayDepth(mixed $value): int
    {
        if (!is_array($value) || $value === []) {
            return 0;
        }

        $depth = 0;
        foreach ($value as $child) {
            $depth = max($depth, $this->arrayDepth($child));
        }

        return $depth + 1;
    }
}
