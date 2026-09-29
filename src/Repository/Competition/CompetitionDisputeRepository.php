<?php

declare(strict_types=1);

namespace App\Repository\Competition;

use App\Entity\Competition\CompetitionDispute;
use App\Entity\Competition\CompetitionMatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CompetitionDispute> */
final class CompetitionDisputeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, CompetitionDispute::class); }

    public function openForMatch(CompetitionMatch $match): ?CompetitionDispute
    {
        return $this->findOneBy(['match' => $match, 'status' => CompetitionDispute::STATUS_OPEN]);
    }
}
