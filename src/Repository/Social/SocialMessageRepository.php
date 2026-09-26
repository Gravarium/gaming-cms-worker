<?php

declare(strict_types=1);

namespace App\Repository\Social;

use App\Entity\Social\SocialConversation;
use App\Entity\Social\SocialMessage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SocialMessage> */
final class SocialMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialMessage::class);
    }

    /** @return list<SocialMessage> */
    public function forConversation(SocialConversation $conversation, int $limit = 100): array
    {
        return $this->createQueryBuilder('message')
            ->andWhere('message.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('message.createdAt', 'DESC')
            ->setMaxResults(max(1, min(200, $limit)))
            ->getQuery()
            ->getResult();
    }

    public function countByAuthorSince(User $author, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('message')
            ->select('COUNT(message.id)')
            ->andWhere('message.author = :author')
            ->andWhere('message.createdAt >= :since')
            ->setParameter('author', $author)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<SocialMessage> */
    public function forAuthor(User $author, int $limit = 1000): array
    {
        return $this->createQueryBuilder('message')
            ->andWhere('message.author = :author')
            ->setParameter('author', $author)
            ->orderBy('message.createdAt', 'DESC')
            ->setMaxResults(max(1, min(5000, $limit)))
            ->getQuery()
            ->getResult();
    }

    /** @return list<SocialMessage> */
    public function before(\DateTimeImmutable $cutoff, int $limit = 500): array
    {
        return $this->createQueryBuilder('message')
            ->andWhere('message.createdAt < :cutoff')
            ->andWhere('message.deletedAt IS NULL')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('message.createdAt', 'ASC')
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();
    }
}
