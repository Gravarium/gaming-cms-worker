<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guild;
use App\Entity\GuildAnnouncement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GuildAnnouncement> */
final class GuildAnnouncementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, GuildAnnouncement::class); }
    /** @return list<GuildAnnouncement> */
    public function forGuild(Guild $guild): array { return $this->findBy(['guild' => $guild], ['pinned' => 'DESC', 'publishedAt' => 'DESC'], 50); }
}
