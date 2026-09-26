<?php

declare(strict_types=1);

namespace App\Social;

use App\Repository\CmsModuleStateRepository;

/**
 * Keeps the social package fail-closed when an explicit module state disables
 * or removes it. A missing state is treated as enabled so the package remains
 * usable before the module catalog has created its first state row.
 */
final readonly class SocialModuleAvailability
{
    public function __construct(private CmsModuleStateRepository $states)
    {
    }

    public function enabled(): bool
    {
        $state = $this->states->find('social');

        return $state === null || ($state->isInstalled() && $state->isEnabled());
    }
}
