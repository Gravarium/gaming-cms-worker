<?php

declare(strict_types=1);

namespace App\VideoWorkspace;

use App\Service\MediaUrlPolicy;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ProviderRegistry
{
    /** @var array<string, ProviderAdapter> */
    private array $providers = [];
    /** @var array<string, array{label:string, documentation:string, capability:string}> */
    private array $catalogue = [];

    /** @param iterable<ProviderAdapter> $adapters */
    public function __construct(#[AutowireIterator('app.video_workspace.provider')] iterable $adapters, private readonly MediaUrlPolicy $urls)
    {
        foreach ($adapters as $adapter) {
            foreach ($adapter->catalogue() as $key => $definition) {
                if (isset($this->providers[$key]) || preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/', $key) !== 1) {
                    throw new \LogicException('Duplicate or malformed video provider.');
                }
                $this->providers[$key] = $adapter;
                $this->catalogue[$key] = $definition;
            }
        }
    }

    /** @return array<string, array{label:string, documentation:string, capability:string}> */
    public function catalogue(): array { return $this->catalogue; }

    /** @return array{mode:string,url:string}|null */
    public function resolve(string $provider, string $url, string $parentHost): ?array
    {
        if (strlen($url) > 700 || !$this->urls->isSafeRemote($url) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }
        $result = ($this->providers[$provider] ?? null)?->resolve($provider, $url, $parentHost);
        if ($result === null || !in_array($result['mode'], ['iframe', 'video', 'hls', 'link'], true)
            || !$this->urls->isSafeRemote($result['url']) || parse_url($result['url'], PHP_URL_SCHEME) !== 'https') {
            return null;
        }
        if ($result['mode'] === 'iframe') {
            $parent = strtolower(rtrim(trim($parentHost, '[]'), '.'));
            $frameHost = strtolower(rtrim(trim((string) parse_url($result['url'], PHP_URL_HOST), '[]'), '.'));
            if ($parent !== '' && $frameHost === $parent) {
                return null;
            }
        }
        return $result;
    }
}
