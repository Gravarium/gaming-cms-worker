<?php

declare(strict_types=1);

namespace App\Repository\Competition;

use App\Entity\Competition\Competition;
use App\Entity\Competition\CompetitionMatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CompetitionMatch> */
final class CompetitionMatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, CompetitionMatch::class); }

    /** @return list<CompetitionMatch> */
    public function forCompetition(Competition $competition): array
    {
        return $this->findBy(['competition' => $competition], ['roundNumber' => 'ASC', 'bracket' => 'ASC', 'sequence' => 'ASC']);
    }
}
