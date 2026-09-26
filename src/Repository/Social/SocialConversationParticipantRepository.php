<?php

declare(strict_types=1);

namespace App\Repository\Social;

use App\Entity\Social\SocialConversation;
use App\Entity\Social\SocialConversationParticipant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SocialConversationParticipant> */
final class SocialConversationParticipantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialConversationParticipant::class);
    }

    public function activeFor(SocialConversation $conversation, User $user): ?SocialConversationParticipant
    {
        return $this->findOneBy([
            'conversation' => $conversation,
            'user' => $user,
            'status' => SocialConversationParticipant::STATUS_ACTIVE,
        ]);
    }

    /** @return list<SocialConversationParticipant> */
    public function activeParticipants(SocialConversation $conversation): array
    {
        return $this->findBy(
            ['conversation' => $conversation, 'status' => SocialConversationParticipant::STATUS_ACTIVE],
            ['joinedAt' => 'ASC'],
        );
    }

    /** @return list<SocialConversationParticipant> */
    public function activeForUser(User $user, int $limit = 200): array
    {
        return $this->createQueryBuilder('participant')
            ->andWhere('participant.user = :user')
            ->andWhere('participant.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', SocialConversationParticipant::STATUS_ACTIVE)
            ->orderBy('participant.joinedAt', 'DESC')
            ->setMaxResults(max(1, min(500, $limit)))
            ->getQuery()
            ->getResult();
    }
}
