<?php

declare(strict_types=1);

namespace App\Gaming\Competition;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use App\Entity\User;

final class CompetitionVisibilityPolicy
{
    public function canView(Competition $competition, ?User $viewer): bool
    {
        if ($competition->getStatus() === Competition::STATUS_DRAFT || $competition->getGame()?->isEnabled() !== true) { return false; }
        if ($competition->isPublic()) { return true; }
        return $viewer !== null && ($competition->getCreatedBy() === $viewer || $viewer->hasPermission('CMS_GAMING_MANAGE'));
    }

    public function canViewMatch(CompetitionMatch $match, ?User $viewer): bool
    {
        $competition = $match->getCompetition();
        return $competition instanceof Competition && ($this->canView($competition, $viewer) || ($viewer !== null && (($match->getParticipantA()?->containsUser($viewer)) || ($match->getParticipantB()?->containsUser($viewer)))));
    }
}
