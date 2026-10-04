<?php

declare(strict_types=1);

namespace App\GuildEventWaitlist;

use App\Entity\GuildEvent;
use App\Entity\GuildEventSignup;
use App\Entity\GuildMember;
use App\Repository\GuildEventSignupRepository;
use App\Service\AuditLogger;

/** Called inside the same transaction and event write lock as the departing signup. */
final readonly class GuildEventWaitlistPromoter
{
    public function __construct(
        private GuildEventSignupRepository $signups,
        private AuditLogger $audit,
    ) {
    }

    public function promoteOne(GuildEvent $event): ?GuildEventSignup
    {
        $guild = $event->getGuild();
        $capacity = $event->getMaxParticipants();
        if ($guild === null || !$guild->isEnabled() || $event->getStatus() !== GuildEvent::STATUS_PLANNED
            || $event->getStartsAt() <= new \DateTimeImmutable() || $capacity === null || $capacity < 1
            || $this->signups->confirmedCount($event) >= $capacity) {
            return null;
        }

        // Read in bounded pages so invalid old entries cannot hide a later eligible member.
        for ($offset = 0; ; $offset += 50) {
            $candidates = $this->signups->waitlistedPage($event, $offset, 50);
            if ($candidates === []) {
                return null;
            }

            foreach ($candidates as $candidate) {
                $member = $candidate->getMember();
                $user = $candidate->getUser();
                if (!$member instanceof GuildMember || $user === null || !$member->isActive()
                    || $member->getGuild()?->getId() !== $guild->getId()
                    || $member->getUser()?->getId() !== $user->getId()) {
                    continue;
                }

                $team = $event->getTeam();
                if ($team !== null && $team->getLeader()?->getId() !== $member->getId()
                    && !$team->getMembers()->contains($member)) {
                    continue;
                }

                $candidate->setResponse(GuildEventSignup::GOING);
                $this->audit->record('guild_event.waitlist_promote', $event, $event->getId(),
                    'A waitlisted member was promoted after a place opened.',
                    ['signup_id' => $candidate->getId(), 'member_id' => $member->getId()]);

                return $candidate;
            }
        }
    }
}
