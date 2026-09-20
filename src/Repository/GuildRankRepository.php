<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guild;
use App\Entity\GuildRank;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GuildRank> */
final class GuildRankRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, GuildRank::class); }

    public function defaultForGuild(Guild $guild): ?GuildRank
    {
        return $this->findOneBy(['guild' => $guild, 'defaultRank' => true, 'enabled' => true])
            ?? $this->findOneBy(['guild' => $guild, 'enabled' => true], ['position' => 'ASC']);
    }
}
