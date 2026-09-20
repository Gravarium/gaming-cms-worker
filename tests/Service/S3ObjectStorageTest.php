<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\S3ObjectStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class S3ObjectStorageTest extends TestCase
{
    public function testUploadUsesSignedPutRequestAndReturnsPublicUrl(): void
    {
        $requestSeen = false;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requestSeen): MockResponse {
            self::assertSame('PUT', $method);
            self::assertSame('https://objects.example.test/cms/gaming/logo%20test.png', $url);
            self::assertArrayHasKey('authorization', $options['normalized_headers']);
            self::assertStringContainsString('AWS4-HMAC-SHA256 Credential=access/', $options['normalized_headers']['authorization'][0]);
            $requestSeen = true;

            return new MockResponse('', ['http_code' => 200]);
        });

        $storage = $this->storage($client);
        $file = $this->temporaryFile('test-content');

        try {
            $url = $storage->upload('gaming/logo test.png', $file, 'image/png');
        } finally {
            @unlink($file);
        }

        self::assertTrue($requestSeen);
        self::assertSame('https://media.example.test/gaming/logo%20test.png', $url);
    }

    public function testDeleteUsesSignedDeleteRequest(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('DELETE', $method);
            self::assertSame('https://objects.example.test/cms/gaming/logo.png', $url);
            self::assertStringContainsString('SignedHeaders=host;x-amz-content-sha256;x-amz-date', $options['normalized_headers']['authorization'][0]);

            return new MockResponse('', ['http_code' => 204]);
        });

        $this->storage($client)->delete('gaming/logo.png');
        self::addToAssertionCount(1);
    }

    public function testConnectionCheckUsesSignedHeadRequestWithoutWritingData(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('HEAD', $method);
            self::assertSame('https://objects.example.test/cms/', $url);
            self::assertStringContainsString('AWS4-HMAC-SHA256 Credential=access/', $options['normalized_headers']['authorization'][0]);

            return new MockResponse('', ['http_code' => 200]);
        });

        $this->storage($client)->checkConnection();
        self::addToAssertionCount(1);
    }

    public function testUploadRejectsTraversalObjectKeyBeforeNetworkRequest(): void
    {
        $file = $this->temporaryFile('test-content');
        try {
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('Objektschlüssel');
            $this->storage(new MockHttpClient())->upload('gaming/../secret.txt', $file, 'text/plain');
        } finally {
            @unlink($file);
        }
    }

    public function testInvalidPublicBaseUrlIsRejectedBeforeUpload(): void
    {
        $file = $this->temporaryFile('test-content');
        $storage = new S3ObjectStorage(
            new MockHttpClient(),
            'https://objects.example.test',
            'eu-central-1',
            'cms',
            'access',
            'secret',
            'javascript:alert(1)',
        );

        try {
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('öffentliche Storage-Adresse');
            $storage->upload('gaming/logo.png', $file, 'image/png');
        } finally {
            @unlink($file);
        }
    }

    public function testEndpointWithEmbeddedCredentialsIsRejected(): void
    {
        $file = $this->temporaryFile('test-content');
        $storage = new S3ObjectStorage(
            new MockHttpClient(),
            'https://user:secret@objects.example.test',
            'eu-central-1',
            'cms',
            'access',
            'secret',
            'https://media.example.test',
        );

        try {
            $this->expectException(\DomainException::class);
            $this->expectExceptionMessage('S3-Endpunkt');
            $storage->upload('gaming/logo.png', $file, 'image/png');
        } finally {
            @unlink($file);
        }
    }

    public function testUploadFailsClearlyWithoutConfiguration(): void
    {
        $storage = new S3ObjectStorage(new MockHttpClient(), '', 'auto', '', '', '', '');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('S3-Speicher');

        $storage->upload('gaming/logo.png', __FILE__, 'image/png');
    }

    private function storage(MockHttpClient $client): S3ObjectStorage
    {
        return new S3ObjectStorage(
            $client,
            'https://objects.example.test',
            'eu-central-1',
            'cms',
            'access',
            'secret',
            'https://media.example.test',
        );
    }

    private function temporaryFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'cms-s3-');
        self::assertNotFalse($file);
        file_put_contents($file, $contents);

        return $file;
    }
}
