<?php

declare(strict_types=1);

namespace App\Profile;

use App\Repository\CmsModuleStateRepository;

final readonly class ProfileModuleAvailability
{
    public function __construct(private CmsModuleStateRepository $states)
    {
    }

    public function enabled(): bool
    {
        return $this->states->find('users')?->isEnabled() ?? true;
    }
}
