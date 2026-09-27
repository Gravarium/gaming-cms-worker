<?php

declare(strict_types=1);

namespace App\Widget;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\User;
use App\Gaming\Competition\CompetitionVisibilityPolicy;
use App\Repository\Competition\CompetitionParticipantRepository;
use App\Security\CmsPermission;

final readonly class CompetitionParticipationQuery
{
    public const MAX_RESULTS = 24;

    public function __construct(
        private CompetitionParticipantRepository $participants,
        private CompetitionVisibilityPolicy $visibility,
    ) {
    }

    /** @return list<CompetitionParticipant> */
    public function forCaptain(User $captain, int $limit = self::MAX_RESULTS): array
    {
        $builder = $this->participants->createQueryBuilder('participant')
            ->innerJoin('participant.competition', 'competition')
            ->addSelect('competition')
            ->innerJoin('competition.game', 'game')
            ->addSelect('game')
            ->andWhere('participant.captain = :captain')
            ->andWhere('competition.status <> :draft')
            ->andWhere('game.enabled = :enabled')
            ->setParameter('captain', $captain)
            ->setParameter('draft', Competition::STATUS_DRAFT)
            ->setParameter('enabled', true)
            ->orderBy('participant.updatedAt', 'DESC')
            ->addOrderBy('participant.id', 'DESC');

        $visibleCompetitions = $builder->expr()->orX(
            $builder->expr()->eq('competition.visibility', ':public'),
            $builder->expr()->eq('competition.createdBy', ':captain'),
        );
        $builder->setParameter('public', Competition::VISIBILITY_PUBLIC);
        if ($captain->hasPermission(CmsPermission::GAMING)) {
            $visibleCompetitions->add($builder->expr()->eq('competition.visibility', ':private'));
            $builder->setParameter('private', Competition::VISIBILITY_PRIVATE);
        }
        $builder->andWhere($visibleCompetitions)
            ->setMaxResults(max(1, min(self::MAX_RESULTS, $limit)));

        /** @var list<CompetitionParticipant> $entries */
        $entries = $builder->getQuery()->getResult();

        return array_values(array_filter(
            $entries,
            fn (CompetitionParticipant $entry): bool => $entry->getCompetition() instanceof Competition
                && $this->visibility->canView($entry->getCompetition(), $captain),
        ));
    }
}
