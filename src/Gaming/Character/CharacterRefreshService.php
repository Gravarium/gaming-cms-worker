<?php

declare(strict_types=1);

namespace App\Gaming\Character;

use App\Entity\GameCharacter\CharacterImportProvenance;
use App\Entity\GameCharacter\CharacterProfile;

final class CharacterRefreshService
{
    public function refresh(CharacterProfile $profile, CharacterRefreshSnapshot $snapshot, int $expectedRefreshVersion): CharacterImportProvenance
    {
        if (!$profile->isImported()) {
            throw new \DomainException('Manual character profiles cannot be refreshed from an import.');
        }
        if ($profile->getExternalId() !== $snapshot->externalId) {
            throw new \DomainException('The imported external identity does not match this profile.');
        }

        $profile->applyImportedData(
            $snapshot->name,
            $snapshot->server,
            $snapshot->region,
            $snapshot->characterClass,
            $snapshot->role,
            $snapshot->level,
            $snapshot->builds,
            $snapshot->professions,
            $snapshot->progression,
            $snapshot->collections,
            $snapshot->payloadHash,
            $expectedRefreshVersion,
            $snapshot->observedAt,
        );

        $provenance = new CharacterImportProvenance(
            $profile,
            $snapshot->provider,
            $snapshot->externalId,
            $snapshot->payloadHash,
            $snapshot->observedAt,
        );
        $profile->addProvenance($provenance);

        return $provenance;
    }
}
