<?php

declare(strict_types=1);

namespace App\Support;

final class CaseAccessPolicy
{
    /** @param list<int> $participantIds */
    public function canView(int $viewerId, array $participantIds, bool $isAssignedStaff, bool $moduleEnabled): bool
    {
        return $moduleEnabled && $viewerId > 0 && ($isAssignedStaff || in_array($viewerId, $participantIds, true));
    }

    public function canViewEvidence(bool $isAssignedStaff, bool $isEvidenceOwner, bool $explicitlyShared): bool
    {
        return $isAssignedStaff || $isEvidenceOwner || $explicitlyShared;
    }
}
