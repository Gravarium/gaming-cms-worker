<?php

declare(strict_types=1);

namespace App\Gaming\Character;

use App\Entity\GameCharacter\CharacterProfile;
use App\Entity\User;

final class CharacterVisibilityPolicy
{
    public function canViewProfile(CharacterProfile $profile, ?User $viewer): bool
    {
        if ($profile->isDeleted()) {
            return false;
        }
        if ($profile->getAccount()->isOwnedBy($viewer)) {
            return true;
        }

        return $profile->isPubliclyVisible() && $profile->consentFor(CharacterProfile::FIELD_NAME)?->isGranted() === true;
    }

    public function canViewField(CharacterProfile $profile, ?User $viewer, string $fieldKey): bool
    {
        if (!in_array($fieldKey, CharacterProfile::FIELDS, true) || $profile->isDeleted()) {
            return false;
        }
        if ($profile->getAccount()->isOwnedBy($viewer)) {
            return true;
        }

        return $profile->isPubliclyVisible() && $profile->consentFor($fieldKey)?->isGranted() === true;
    }

    /** @return array<string, mixed> */
    public function visibleFields(CharacterProfile $profile, ?User $viewer): array
    {
        $visible = [];
        foreach (CharacterProfile::FIELDS as $fieldKey) {
            if ($this->canViewField($profile, $viewer, $fieldKey)) {
                $visible[$fieldKey] = $profile->getFieldValue($fieldKey);
            }
        }

        return $visible;
    }
}
