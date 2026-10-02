<?php

declare(strict_types=1);

namespace App\CompetitionMatchInbox;

use App\Entity\Competition\CompetitionMatch;
use App\Entity\User;
use App\Gaming\Competition\CompetitionVisibilityPolicy;
use App\Repository\Competition\CompetitionMatchRepository;

final readonly class CaptainMatchInbox
{
    public const MAX_RESULTS = 50;

    public function __construct(
        private CompetitionMatchRepository $matches,
        private CompetitionVisibilityPolicy $visibility,
    ) {
    }

    /** @return list<CompetitionMatch> */
    public function forCaptain(User $captain): array
    {
        $builder = $this->matches->createQueryBuilder('m')
            ->innerJoin('m.competition', 'competition')->addSelect('competition')
            ->innerJoin('competition.game', 'game')->addSelect('game')
            ->leftJoin('m.participantA', 'participantA')->addSelect('participantA')
            ->leftJoin('m.participantB', 'participantB')->addSelect('participantB')
            ->andWhere('(participantA.captain = :captain OR participantB.captain = :captain)')
            ->andWhere('m.status <> :cancelled')
            ->setParameter('captain', $captain)
            ->setParameter('cancelled', CompetitionMatch::STATUS_CANCELLED)
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults(self::MAX_RESULTS);

        /** @var list<CompetitionMatch> $matches */
        $matches = $builder->getQuery()->getResult();

        return array_values(array_filter(
            $matches,
            fn (CompetitionMatch $match): bool => $this->visibility->canViewMatch($match, $captain),
        ));
    }
}
