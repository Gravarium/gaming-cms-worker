<?php

declare(strict_types=1);

namespace App\VideoProviderApi;

use App\VideoWorkspace\ProviderRegistry;

/** A local-only adapter surface: resolving a URL never fetches the provider. */
final readonly class ProviderCapabilities
{
    public function __construct(private ProviderRegistry $providers) {}

    /** @return array<string, array{label:string, documentation:string, capability:string}> */
    public function catalogue(): array
    {
        $result = $this->providers->catalogue();
        ksort($result);

        return $result;
    }

    /** @return array{mode:string,url:string}|null */
    public function resolve(string $provider, string $url, string $parentHost): ?array
    {
        if (!isset($this->providers->catalogue()[$provider]) || strlen($url) > 700) {
            return null;
        }

        return $this->providers->resolve($provider, $url, $parentHost);
    }
}
