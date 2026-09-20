<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guild;
use App\Entity\GuildDiscordIntegration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GuildDiscordIntegration> */
final class GuildDiscordIntegrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuildDiscordIntegration::class);
    }

    public function forGuild(Guild $guild): ?GuildDiscordIntegration
    {
        return $this->findOneBy(['guild' => $guild]);
    }
}
