<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guild;
use App\Entity\GuildEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GuildEvent> */
final class GuildEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, GuildEvent::class); }

    /** @return list<GuildEvent> */
    public function upcomingForGuild(Guild $guild): array
    {
        return $this->createQueryBuilder('event')->andWhere('event.guild = :guild')->andWhere('event.startsAt >= :now')->andWhere('event.status = :status')->setParameter('guild', $guild)->setParameter('now', new \DateTimeImmutable('-2 hours'))->setParameter('status', GuildEvent::STATUS_PLANNED)->orderBy('event.startsAt', 'ASC')->getQuery()->getResult();
    }
}
