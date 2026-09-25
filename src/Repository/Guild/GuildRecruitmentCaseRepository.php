<?php

declare(strict_types=1);

namespace App\Repository\Guild;

use App\Entity\Guild;
use App\Entity\Guild\GuildRecruitmentCase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GuildRecruitmentCase> */
final class GuildRecruitmentCaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry){parent::__construct($registry,GuildRecruitmentCase::class);}

    /** @return list<GuildRecruitmentCase> */
    public function activeForGuild(Guild $guild):array
    {
        return $this->createQueryBuilder('recruitment')->andWhere('recruitment.guild = :guild')->setParameter('guild',$guild)->andWhere('recruitment.status IN (:states)')->setParameter('states',['submitted','review','trial'])->getQuery()->getResult();
    }
}
