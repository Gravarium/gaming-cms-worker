<?php

declare(strict_types=1);

namespace App\Community\Interaction;

final class InteractionAuthorization
{
    public function canView(?InteractionTargetContext $target, InteractionActor $actor): bool
    {
        if ($target === null || !$target->moduleEnabled) {
            return false;
        }

        return $target->publiclyVisible || $actor->moderator || $target->ownedBy($actor);
    }

    public function canInteract(?InteractionTargetContext $target, InteractionActor $actor): bool
    {
        return $actor->isAuthenticated() && $this->canView($target, $actor);
    }

    public function canModerate(?InteractionTargetContext $target, InteractionActor $actor): bool
    {
        return $target !== null && $target->moduleEnabled && $actor->isAuthenticated() && $actor->moderator;
    }
}
