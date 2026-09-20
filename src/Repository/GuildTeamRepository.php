<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guild;
use App\Entity\GuildTeam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GuildTeam> */
final class GuildTeamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, GuildTeam::class); }
    /** @return list<GuildTeam> */
    public function forGuild(Guild $guild): array { return $this->findBy(['guild' => $guild], ['active' => 'DESC', 'name' => 'ASC']); }
}
