<?php

declare(strict_types=1);

namespace App\Repository\Social;

use App\Entity\Social\SocialConversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SocialConversation> */
final class SocialConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialConversation::class);
    }

    /** @return list<SocialConversation> */
    public function forUser(User $user, int $limit = 100): array
    {
        return $this->createQueryBuilder('conversation')
            ->join('App\\Entity\\Social\\SocialConversationParticipant', 'participant', 'WITH', 'participant.conversation = conversation')
            ->andWhere('participant.user = :user')
            ->andWhere('participant.status = :active')
            ->andWhere('conversation.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('active', 'active')
            ->orderBy('COALESCE(conversation.lastMessageAt, conversation.createdAt)', 'DESC')
            ->setMaxResults(max(1, min(200, $limit)))
            ->getQuery()
            ->getResult();
    }

    public function directBetween(User $first, User $second): ?SocialConversation
    {
        return $this->createQueryBuilder('conversation')
            ->join('App\\Entity\\Social\\SocialConversationParticipant', 'firstParticipant', 'WITH', 'firstParticipant.conversation = conversation')
            ->join('App\\Entity\\Social\\SocialConversationParticipant', 'secondParticipant', 'WITH', 'secondParticipant.conversation = conversation')
            ->andWhere('conversation.type = :type')
            ->andWhere('conversation.deletedAt IS NULL')
            ->andWhere('firstParticipant.user = :first AND firstParticipant.status = :active')
            ->andWhere('secondParticipant.user = :second AND secondParticipant.status = :active')
            ->setParameter('type', SocialConversation::TYPE_DIRECT)
            ->setParameter('first', $first)
            ->setParameter('second', $second)
            ->setParameter('active', 'active')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
