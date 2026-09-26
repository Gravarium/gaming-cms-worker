<?php

declare(strict_types=1);

namespace App\Repository\Profile;

use App\Entity\Profile\ProfileDeletionRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProfileDeletionRequest> */
final class ProfileDeletionRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProfileDeletionRequest::class);
    }

    /** @return list<ProfileDeletionRequest> */
    public function due(\DateTimeImmutable $now, int $limit = 100): array
    {
        return $this->createQueryBuilder('request')
            ->andWhere('request.status = :pending')
            ->andWhere('request.executeAfter <= :now')
            ->setParameter('pending', ProfileDeletionRequest::STATUS_PENDING)
            ->setParameter('now', $now)
            ->orderBy('request.executeAfter', 'ASC')
            ->setMaxResults(max(1, min(500, $limit)))
            ->getQuery()
            ->getResult();
    }
}
