<?php

declare(strict_types=1);

namespace App\Downloads;

use App\Repository\CmsModuleStateRepository;

final readonly class DownloadModuleAvailability
{
    public function __construct(private CmsModuleStateRepository $states)
    {
    }

    public function enabled(): bool
    {
        return $this->states->find('downloads')?->isEnabled() ?? true;
    }
}
