<?php

declare(strict_types=1);

namespace App\Repository\Social;

use App\Entity\Social\SocialBlock;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SocialBlock> */
final class SocialBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialBlock::class);
    }

    public function directed(User $blocker, User $blocked): ?SocialBlock
    {
        return $this->findOneBy(['blocker' => $blocker, 'blocked' => $blocked]);
    }

    public function isBlockedEitherDirection(User $first, User $second, ?\DateTimeImmutable $at = null): bool
    {
        $at ??= new \DateTimeImmutable();

        return (int) $this->createQueryBuilder('block')
            ->select('COUNT(block.id)')
            ->andWhere('(block.blocker = :first AND block.blocked = :second) OR (block.blocker = :second AND block.blocked = :first)')
            ->andWhere('block.liftedAt IS NULL OR block.liftedAt > :at')
            ->setParameter('first', $first)
            ->setParameter('second', $second)
            ->setParameter('at', $at)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** @return list<SocialBlock> */
    public function activeFor(User $user, ?\DateTimeImmutable $at = null): array
    {
        $at ??= new \DateTimeImmutable();

        return $this->createQueryBuilder('block')
            ->andWhere('(block.blocker = :user OR block.blocked = :user)')
            ->andWhere('block.liftedAt IS NULL OR block.liftedAt > :at')
            ->setParameter('user', $user)
            ->setParameter('at', $at)
            ->orderBy('block.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
