<?php

declare(strict_types=1);

namespace App\Repository\Competition;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionParticipant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CompetitionParticipant> */
final class CompetitionParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, CompetitionParticipant::class); }

    /** @return list<CompetitionParticipant> */
    public function forCompetition(Competition $competition): array
    {
        return $this->findBy(['competition' => $competition], ['seed' => 'ASC', 'registeredAt' => 'ASC']);
    }

    public function forCompetitionAndUser(Competition $competition, User $user): ?CompetitionParticipant
    {
        return $this->createQueryBuilder('participant')
            ->andWhere('participant.competition = :competition')
            ->andWhere('participant.captain = :user')
            ->setParameter('competition', $competition)
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return list<CompetitionParticipant> */
    public function checkedInFor(Competition $competition): array
    {
        return $this->findBy(['competition' => $competition, 'status' => CompetitionParticipant::STATUS_CHECKED_IN], ['seed' => 'ASC', 'registeredAt' => 'ASC']);
    }
}
