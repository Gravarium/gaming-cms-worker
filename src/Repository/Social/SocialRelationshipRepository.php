<?php

declare(strict_types=1);

namespace App\Repository\Social;

use App\Entity\Social\SocialRelationship;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SocialRelationship> */
final class SocialRelationshipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialRelationship::class);
    }

    public function directed(User $requester, User $recipient, string $type): ?SocialRelationship
    {
        return $this->findOneBy(['requester' => $requester, 'recipient' => $recipient, 'type' => $type]);
    }

    public function acceptedBetween(User $first, User $second, string $type = SocialRelationship::TYPE_FRIEND): bool
    {
        return (int) $this->createQueryBuilder('relationship')
            ->select('COUNT(relationship.id)')
            ->andWhere('relationship.type = :type')
            ->andWhere('relationship.state = :state')
            ->andWhere('(relationship.requester = :first AND relationship.recipient = :second) OR (relationship.requester = :second AND relationship.recipient = :first)')
            ->setParameter('type', $type)
            ->setParameter('state', SocialRelationship::STATE_ACCEPTED)
            ->setParameter('first', $first)
            ->setParameter('second', $second)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function countCreatedBySince(User $requester, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('relationship')
            ->select('COUNT(relationship.id)')
            ->andWhere('relationship.requester = :requester')
            ->andWhere('relationship.createdAt >= :since')
            ->setParameter('requester', $requester)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<SocialRelationship> */
    public function forUser(User $user, int $limit = 200): array
    {
        return $this->createQueryBuilder('relationship')
            ->andWhere('(relationship.requester = :user OR relationship.recipient = :user)')
            ->andWhere('relationship.state IN (:states)')
            ->setParameter('user', $user)
            ->setParameter('states', [SocialRelationship::STATE_PENDING, SocialRelationship::STATE_ACCEPTED])
            ->orderBy('relationship.createdAt', 'DESC')
            ->setMaxResults(max(1, min(500, $limit)))
            ->getQuery()
            ->getResult();
    }
}
