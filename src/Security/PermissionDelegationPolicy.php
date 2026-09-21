<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AccessRole;
use App\Entity\User;

final class PermissionDelegationPolicy
{
    /** @param iterable<string> $permissions */
    public function canDelegatePermissions(User $actor, iterable $permissions): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        $allowed = array_fill_keys($actor->getEffectivePermissions(), true);
        foreach ($permissions as $permission) {
            if (!isset($allowed[$permission])) {
                return false;
            }
        }

        return true;
    }

    /** @param iterable<AccessRole> $roles */
    public function canDelegateRoles(User $actor, iterable $roles): bool
    {
        foreach ($roles as $role) {
            if (!$this->canDelegatePermissions($actor, $role->getPermissions())) {
                return false;
            }
        }

        return true;
    }

    public function canManageUser(User $actor, User $target): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        if ($target->isAdmin()) {
            return false;
        }

        return $this->canDelegatePermissions($actor, $target->getEffectivePermissions());
    }

    public function canManageRole(User $actor, AccessRole $role): bool
    {
        return $actor->isAdmin() || $this->canDelegatePermissions($actor, $role->getPermissions());
    }
}
