<?php

declare(strict_types=1);

namespace App\Tests\ExtensionRuntime;

use App\ExternalConnector\ExternalNotificationDispatcher;
use App\ExtensionPackage\ExtensionCapabilityGate;
use App\ExtensionPackage\ExtensionCapabilityPolicy;
use App\ExtensionPackage\ExtensionManifest;
use App\ExtensionPackage\ExtensionPermissionStore;
use App\ExtensionRuntime\ExtensionOutboundUrlPolicy;
use App\ExtensionRuntime\ExtensionRuntimeAudit;
use App\ExtensionRuntime\ExtensionRuntimeBroker;
use App\ExtensionRuntime\ExtensionRuntimeContext;
use App\ExtensionRuntime\ExtensionRuntimeState;
use App\Repository\ContentEntryRepository;
use App\Repository\MediaAssetRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ExtensionRuntimeBrokerBoundaryTest extends KernelTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->directory = sys_get_temp_dir().'/cms-extension-broker-'.bin2hex(random_bytes(6));
        if (!mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Temporary extension test directory could not be created.');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                @unlink($file);
            }
        }
        @rmdir($this->directory);
    }

    public function testOversizedResponseIsRejectedAndCanceledWhileStreaming(): void
    {
        $response = new MockResponse(
            static function (): \Generator {
                for ($index = 0; $index < 33; ++$index) {
                    yield str_repeat('x', 8192);
                }
            },
            ['response_headers' => ['content-type' => ['application/json']]],
        );
        $requestOptions = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($response, &$requestOptions): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://8.8.8.8/data', $url);
            $requestOptions = $options;

            return $response;
        });

        try {
            $this->broker($client)->fetchJson($this->context(), 'https://8.8.8.8/data');
            self::fail('An oversized extension response was accepted.');
        } catch (\DomainException $exception) {
            self::assertSame('Extension HTTP response exceeds 256 KiB.', $exception->getMessage());
        }

        self::assertIsArray($requestOptions);
        self::assertFalse($requestOptions['buffer'] ?? true);
        self::assertTrue($response->getInfo('canceled') === true);
    }

    public function testSmallJsonResponseKeepsTheExistingReturnContract(): void
    {
        $response = new MockResponse('{"ok":true}', [
            'http_code' => 201,
            'response_headers' => ['content-type' => ['application/json; charset=utf-8']],
        ]);
        $requestOptions = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($response, &$requestOptions): MockResponse {
            $requestOptions = $options;

            return $response;
        });

        $result = $this->broker($client)->fetchJson($this->context(), 'https://8.8.8.8/data');

        self::assertSame([
            'status' => 201,
            'contentType' => 'application/json; charset=utf-8',
            'body' => '{"ok":true}',
        ], $result);
        self::assertIsArray($requestOptions);
        self::assertFalse($requestOptions['buffer'] ?? true);
    }

    private function broker(MockHttpClient $http): ExtensionRuntimeBroker
    {
        $manifest = $this->manifest();
        $permissions = new ExtensionPermissionStore(
            $this->directory.'/permissions.json',
            new ExtensionCapabilityPolicy(),
        );
        $permissions->grant($manifest, 'http.outbound');
        $container = self::getContainer();

        return new ExtensionRuntimeBroker(
            new ExtensionCapabilityGate($permissions),
            new ExtensionRuntimeState($this->directory.'/state.json'),
            new ExtensionRuntimeAudit($this->directory.'/audit.jsonl'),
            new ExtensionOutboundUrlPolicy(),
            $container->get(ContentEntryRepository::class),
            $container->get(MediaAssetRepository::class),
            $container->get(ExternalNotificationDispatcher::class),
            $http,
        );
    }

    private function context(): ExtensionRuntimeContext
    {
        return new ExtensionRuntimeContext($this->manifest());
    }

    private function manifest(): ExtensionManifest
    {
        return new ExtensionManifest(
            'module',
            'bounded-http',
            'Bounded HTTP fixture',
            '1.0.0',
            '^1.0',
            [],
            ['http.outbound'],
        );
    }
}
