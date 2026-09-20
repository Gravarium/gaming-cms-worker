<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\ExternalConnectorTargetDefinition;
use App\ExternalConnector\ExternalMediaUpload;
use App\ExternalConnector\S3CompatibleMediaConnectorAdapter;
use App\ExternalConnector\S3MediaTargetConfigurationProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class S3CompatibleMediaConnectorAdapterTest extends TestCase
{
    private string $uploadFile;

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'media-upload-');
        if ($file === false) {
            throw new \RuntimeException('Temporary test file could not be created.');
        }
        $this->uploadFile = $file;
        file_put_contents($this->uploadFile, 'media-content');
    }

    protected function tearDown(): void
    {
        @unlink($this->uploadFile);
    }

    public function testStoresAndDeletesUsingConfigurationContract(): void
    {
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options['headers'] ?? []];

            return new MockResponse('', ['http_code' => $method === 'PUT' ? 201 : 204]);
        });
        $adapter = new S3CompatibleMediaConnectorAdapter($client, $this->configurationProvider());
        $target = $this->target();

        $stored = $adapter->store($target, new ExternalMediaUpload('video/demo file.mp4', $this->uploadFile, 'video/mp4'));
        $adapter->delete($target, $stored->objectKey);

        self::assertSame('https://cdn.example.invalid/video/demo%20file.mp4', $stored->location);
        self::assertSame(strlen('media-content'), $stored->size);
        self::assertSame('PUT', $requests[0][0]);
        self::assertSame('DELETE', $requests[1][0]);
        self::assertStringContainsString('/cms-media/video/demo%20file.mp4', $requests[0][1]);
    }

    public function testRejectsMissingConfigurationReferenceWithoutLeakingSecrets(): void
    {
        $adapter = new S3CompatibleMediaConnectorAdapter(new MockHttpClient(), $this->configurationProvider());
        $target = new ExternalConnectorTargetDefinition(
            ExternalConnectorTarget::CAPABILITY_MEDIA,
            'missing',
            S3CompatibleMediaConnectorAdapter::PROVIDER_KEY,
            'Missing',
            true,
            100,
            'media.missing',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No private S3 media configuration exists');
        $adapter->store($target, new ExternalMediaUpload('file.bin', $this->uploadFile));
    }

    private function configurationProvider(): S3MediaTargetConfigurationProvider
    {
        return new class implements S3MediaTargetConfigurationProvider {
            public function forReference(string $reference): array
            {
                if ($reference !== 'media.primary') {
                    throw new \RuntimeException('No private S3 media configuration exists for the selected reference.');
                }

                return [
                    'endpoint' => 'https://objects.example.invalid',
                    'region' => 'eu-test-1',
                    'bucket' => 'cms-media',
                    'access_key' => 'test-access',
                    'secret_key' => 'test-secret',
                    'public_url' => 'https://cdn.example.invalid',
                ];
            }
        };
    }

    private function target(): ExternalConnectorTargetDefinition
    {
        return new ExternalConnectorTargetDefinition(
            ExternalConnectorTarget::CAPABILITY_MEDIA,
            'primary',
            S3CompatibleMediaConnectorAdapter::PROVIDER_KEY,
            'Primary',
            true,
            10,
            'media.primary',
        );
    }
}
