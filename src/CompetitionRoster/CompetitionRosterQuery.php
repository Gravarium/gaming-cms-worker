<?php

declare(strict_types=1);

namespace App\CompetitionRoster;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\User;
use App\Repository\Competition\CompetitionParticipantRepository;
use App\Repository\UserRepository;

final readonly class CompetitionRosterQuery
{
    public const MAX_RESULTS = 20;

    public function __construct(
        private CompetitionParticipantRepository $participants,
        private UserRepository $users,
    ) {
    }

    /**
     * @return list<array{
     *     competition: Competition,
     *     participant: CompetitionParticipant,
     *     members: list<array{id: int, name: string}>,
     *     rosterCount: int,
     *     teamSize: int,
     *     openSlots: int
     * }>
     */
    public function forCaptain(User $captain, int $limit = self::MAX_RESULTS): array
    {
        $builder = $this->participants->createQueryBuilder('participant')
            ->innerJoin('participant.competition', 'competition')
            ->addSelect('competition')
            ->innerJoin('competition.game', 'game')
            ->addSelect('game')
            ->andWhere('participant.captain = :captain')
            ->andWhere('participant.kind = :participantKind')
            ->andWhere('participant.status = :participantStatus')
            ->andWhere('competition.mode = :competitionMode')
            ->andWhere('competition.status = :competitionStatus')
            ->andWhere('competition.visibility = :visibility')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('captain', $captain)
            ->setParameter('participantKind', CompetitionParticipant::KIND_TEAM)
            ->setParameter('participantStatus', CompetitionParticipant::STATUS_REGISTERED)
            ->setParameter('competitionMode', Competition::MODE_TEAM)
            ->setParameter('competitionStatus', Competition::STATUS_OPEN)
            ->setParameter('visibility', Competition::VISIBILITY_PUBLIC)
            ->setParameter('enabled', true)
            ->orderBy('competition.startsAt', 'ASC')
            ->addOrderBy('participant.id', 'ASC')
            ->setMaxResults(max(1, min(self::MAX_RESULTS, $limit)));

        /** @var list<CompetitionParticipant> $participants */
        $participants = $builder->getQuery()->getResult();

        if ($participants === []) {
            return [];
        }

        $allIds = [];
        foreach ($participants as $participant) {
            $captainId = $participant->getCaptain()?->getId();
            if ($captainId !== null) {
                $allIds[] = $captainId;
            }
            foreach ($participant->getRosterUserIds() as $userId) {
                $allIds[] = $userId;
            }
        }
        $allIds = array_values(array_unique(array_filter(
            $allIds,
            static fn (mixed $id): bool => self::isPositiveUserId($id),
        )));

        /** @var array<int, array{id: int, name: string}> $memberMap */
        $memberMap = [];
        if ($allIds !== []) {
            /** @var list<User> $users */
            $users = $this->users->createQueryBuilder('rosterMember')
                ->andWhere('rosterMember.id IN (:ids)')
                ->andWhere('rosterMember.isActive = :active')
                ->setParameter('ids', $allIds)
                ->setParameter('active', true)
                ->getQuery()
                ->getResult();

            foreach ($users as $user) {
                $id = $user->getId();
                if ($id !== null) {
                    $memberMap[$id] = ['id' => $id, 'name' => $user->getDisplayName()];
                }
            }
        }

        $rows = [];
        foreach ($participants as $participant) {
            $competition = $participant->getCompetition();
            if (!$competition instanceof Competition) {
                continue;
            }

            $captainId = $participant->getCaptain()?->getId();
            $memberIds = $participant->getRosterUserIds();
            if ($captainId !== null) {
                array_unshift($memberIds, $captainId);
            }
            $memberIds = array_values(array_unique(array_filter(
                $memberIds,
                static fn (mixed $id): bool => self::isPositiveUserId($id),
            )));

            $members = [];
            foreach ($memberIds as $memberId) {
                $members[] = $memberMap[$memberId] ?? ['id' => $memberId, 'name' => 'Nicht verfügbares Mitglied'];
            }

            $rosterCount = count($memberIds);
            $teamSize = $competition->getTeamSize();
            $rows[] = [
                'competition' => $competition,
                'participant' => $participant,
                'members' => $members,
                'rosterCount' => $rosterCount,
                'teamSize' => $teamSize,
                'openSlots' => max(0, $teamSize - $rosterCount),
            ];
        }

        return $rows;
    }

    private static function isPositiveUserId(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }
}
