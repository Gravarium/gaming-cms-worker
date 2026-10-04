<?php

declare(strict_types=1);

namespace App\Widget\Social;

use App\Entity\Social\SocialConversationParticipant;
use App\Entity\Social\SocialMessage;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;

final readonly class UnreadConversationsQuery
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{unreadConversations: int, unreadMessages: int}
     */
    public function forUser(User $user): array
    {
        $result = $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT conversation.id) AS unreadConversations')
            ->addSelect('COUNT(message.id) AS unreadMessages')
            ->from(SocialConversationParticipant::class, 'participant')
            ->innerJoin('participant.conversation', 'conversation')
            ->innerJoin(SocialMessage::class, 'message', Join::WITH, 'message.conversation = conversation')
            ->andWhere('participant.user = :user')
            ->andWhere('participant.status = :active')
            ->andWhere('conversation.deletedAt IS NULL')
            ->andWhere('message.deletedAt IS NULL')
            ->andWhere('message.author != :user')
            ->andWhere('message.createdAt >= participant.joinedAt')
            ->andWhere('(participant.lastReadAt IS NULL OR message.createdAt > participant.lastReadAt)')
            ->setParameter('user', $user)
            ->setParameter('active', SocialConversationParticipant::STATUS_ACTIVE)
            ->getQuery()
            ->getSingleResult();

        if (!is_array($result)) {
            return ['unreadConversations' => 0, 'unreadMessages' => 0];
        }

        $unreadConversations = $result['unreadConversations'] ?? 0;
        $unreadMessages = $result['unreadMessages'] ?? 0;

        return [
            'unreadConversations' => is_numeric($unreadConversations) ? (int) $unreadConversations : 0,
            'unreadMessages' => is_numeric($unreadMessages) ? (int) $unreadMessages : 0,
        ];
    }
}
