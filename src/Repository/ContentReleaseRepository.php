<?php

declare(strict_types=1);
namespace App\Repository;
use App\Entity\ContentRelease;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
/** @extends ServiceEntityRepository<ContentRelease> */
final class ContentReleaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, ContentRelease::class); }
    /** @return list<ContentRelease> */
    public function findDue(\DateTimeImmutable $now, int $limit = 50): array
    {
        return $this->createQueryBuilder('release')->andWhere('release.status = :status')->setParameter('status', ContentRelease::STATUS_SCHEDULED)->andWhere('release.scheduledAt <= :now')->setParameter('now', $now)->orderBy('release.scheduledAt', 'ASC')->setMaxResults(max(1, min(100, $limit)))->getQuery()->getResult();
    }
}
