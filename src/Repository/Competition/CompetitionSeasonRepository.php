<?php

declare(strict_types=1);

namespace App\Repository\Competition;

use App\Entity\Competition\CompetitionSeason;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CompetitionSeason> */
final class CompetitionSeasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, CompetitionSeason::class); }
}
