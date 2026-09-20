<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuildApplication;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GuildApplication> */
final class GuildApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, GuildApplication::class); }
}
