<?php

declare(strict_types=1);

namespace App\VideoWorkspace;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.video_workspace.provider')]
interface ProviderAdapter
{
    /** @return array<string, array{label:string, documentation:string, capability:string}> */
    public function catalogue(): array;

    /** @return array{mode:string,url:string}|null */
    public function resolve(string $provider, string $sourceUrl, string $parentHost): ?array;
}
