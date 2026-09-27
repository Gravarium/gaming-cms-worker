<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guild;
use App\Entity\GuildEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<GuildEvent> */
final class GuildEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, GuildEvent::class); }

    public function countForGuild(Guild $guild, string $query, ?string $status): int
    {
        $builder = $this->createQueryBuilder('event')
            ->select('COUNT(event.id)')
            ->andWhere('event.guild = :guild')
            ->setParameter('guild', $guild);
        $this->applyAdminFilters($builder, $query, $status);

        return (int) $builder->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<GuildEvent>
     */
    public function findForGuild(
        Guild $guild,
        string $query,
        ?string $status,
        int $limit,
        int $offset,
    ): array {
        $builder = $this->createQueryBuilder('event')
            ->andWhere('event.guild = :guild')
            ->setParameter('guild', $guild)
            ->orderBy('event.startsAt', 'DESC')
            ->addOrderBy('event.id', 'DESC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)));
        $this->applyAdminFilters($builder, $query, $status);

        return $builder->getQuery()->getResult();
    }

    /** @return list<GuildEvent> */
    public function upcomingForGuild(Guild $guild): array
    {
        return $this->createQueryBuilder('event')->andWhere('event.guild = :guild')->andWhere('event.startsAt >= :now')->andWhere('event.status = :status')->setParameter('guild', $guild)->setParameter('now', new \DateTimeImmutable('-2 hours'))->setParameter('status', GuildEvent::STATUS_PLANNED)->orderBy('event.startsAt', 'ASC')->getQuery()->getResult();
    }

    private function applyAdminFilters(QueryBuilder $builder, string $query, ?string $status): void
    {
        $query = mb_strtolower(trim($query));
        if ($query !== '') {
            $builder
                ->andWhere('(LOWER(event.title) LIKE :search OR LOWER(event.description) LIKE :search)')
                ->setParameter('search', '%'.$query.'%');
        }

        if ($status !== null) {
            $builder
                ->andWhere('event.status = :status')
                ->setParameter('status', $status);
        }
    }
}
