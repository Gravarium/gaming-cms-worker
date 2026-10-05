<?php

declare(strict_types=1);

namespace App\VideoProviderAudit;

use App\Entity\VideoWorkspace\VideoSource;
use App\VideoWorkspace\ProviderRegistry;

final readonly class SourceAudit
{
    public function __construct(private ProviderRegistry $providers) {}

    /** @return array{status:string, mode:?string} */
    public function classify(VideoSource $source, string $parentHost): array
    {
        if (!array_key_exists($source->getProvider(), $this->providers->catalogue())) {
            return ['status' => 'unknown_provider', 'mode' => null];
        }

        $resolved = $this->providers->resolve($source->getProvider(), $source->getUrl(), $parentHost);
        if ($resolved === null) {
            return ['status' => 'invalid_link', 'mode' => null];
        }

        if (!$source->isAuthorized()) {
            return ['status' => 'unapproved', 'mode' => $resolved['mode']];
        }

        if (!$source->isEnabled()) {
            return ['status' => 'disabled', 'mode' => $resolved['mode']];
        }

        return ['status' => 'configured', 'mode' => $resolved['mode']];
    }
}
