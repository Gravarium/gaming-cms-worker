<?php

declare(strict_types=1);

namespace App\Gaming\Competition;

use App\Entity\Competition\CompetitionMatch;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\User;

final class CompetitionResultPolicy
{
    public function canSubmit(CompetitionMatch $match, CompetitionParticipant $participant, User $actor): bool
    {
        return $match->isParticipant($participant)
            && $participant->containsUser($actor)
            && $participant->isCheckedIn()
            && in_array($match->getStatus(), [CompetitionMatch::STATUS_READY, CompetitionMatch::STATUS_IN_PROGRESS], true);
    }

    public function canConfirm(CompetitionMatch $match, CompetitionParticipant $participant, User $actor): bool
    {
        return $this->canSubmit($match, $participant, $actor) && $match->getScoreA() !== null && $match->getScoreB() !== null;
    }

    public function canOpenDispute(CompetitionMatch $match, CompetitionParticipant $participant, User $actor): bool
    {
        return $this->canSubmit($match, $participant, $actor) && $match->getStatus() === CompetitionMatch::STATUS_PENDING_CONFIRMATION;
    }
}
