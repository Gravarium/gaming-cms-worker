<?php

declare(strict_types=1);

namespace App\Social;

use App\Entity\Social\SocialAttachment;
use App\Entity\Social\SocialConversation;
use App\Entity\Social\SocialConversationParticipant;
use App\Entity\Social\SocialMessage;
use App\Entity\User;
use App\Repository\Social\SocialConversationParticipantRepository;
use App\Repository\Social\SocialConversationRepository;
use App\Repository\Social\SocialMessageRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SocialMessagingService
{
    public function __construct(
        private SocialModuleAvailability $availability,
        private SocialAccessPolicy $access,
        private SocialRateLimitPolicy $rateLimits,
        private SocialRetentionPolicy $retention,
        private SocialConversationRepository $conversations,
        private SocialConversationParticipantRepository $participants,
        private SocialMessageRepository $messages,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function createDirect(User $actor, User $recipient): SocialConversation
    {
        $this->assertEnabled();
        if (!$this->access->canStartConversation($actor, $recipient)) {
            throw new \DomainException('This user does not accept a conversation from you.');
        }

        $existing = $this->conversations->directBetween($actor, $recipient);
        if ($existing instanceof SocialConversation) {
            return $existing;
        }

        $conversation = new SocialConversation($actor, SocialConversation::TYPE_DIRECT);
        $this->entityManager->persist($conversation);
        $this->entityManager->persist(new SocialConversationParticipant($conversation, $actor, SocialConversationParticipant::ROLE_OWNER));
        $this->entityManager->persist(new SocialConversationParticipant($conversation, $recipient));

        return $conversation;
    }

    /** @param list<User> $members */
    public function createGroup(User $actor, string $title, array $members): SocialConversation
    {
        $this->assertEnabled();
        $unique = [];
        foreach ([$actor, ...$members] as $member) {
            if (!$member->isActive()) {
                throw new \DomainException('All conversation participants must be active users.');
            }
            $key = $member->getId() !== null ? 'id:'.$member->getId() : 'object:'.spl_object_id($member);
            $unique[$key] = $member;
        }
        $unique = array_values($unique);
        $this->rateLimits->assertGroupSize(count($unique));
        foreach ($unique as $member) {
            if (!$this->sameUser($actor, $member) && !$this->access->canStartConversation($actor, $member)) {
                throw new \DomainException('One of the selected users does not accept a conversation from you.');
            }
        }

        $conversation = new SocialConversation($actor, SocialConversation::TYPE_GROUP, $title);
        $this->entityManager->persist($conversation);
        foreach ($unique as $member) {
            $this->entityManager->persist(new SocialConversationParticipant(
                $conversation,
                $member,
                $this->sameUser($actor, $member) ? SocialConversationParticipant::ROLE_OWNER : SocialConversationParticipant::ROLE_MEMBER,
            ));
        }

        return $conversation;
    }

    public function send(User $actor, SocialConversation $conversation, string $body): SocialMessage
    {
        $this->assertEnabled();
        if (!$this->access->canWriteConversation($actor, $conversation)) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Conversation membership is required.');
        }
        $now = new \DateTimeImmutable();
        $this->rateLimits->assertMessageWindow($this->messages->countByAuthorSince($actor, $now->modify('-1 hour')));
        $message = new SocialMessage($conversation, $actor, $body);
        $this->entityManager->persist($message);

        return $message;
    }

    public function attach(
        User $actor,
        SocialMessage $message,
        string $storageLocator,
        string $originalName,
        string $mimeType,
        int $fileSize,
    ): SocialAttachment {
        $this->assertEnabled();
        if (!$this->access->canWriteConversation($actor, $message->getConversation())) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Conversation membership is required.');
        }
        if (!$this->sameUser($actor, $message->getAuthor())) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Only the message author can attach files.');
        }

        $attachment = new SocialAttachment($message, $actor, $storageLocator, $originalName, $mimeType, $fileSize);
        $this->entityManager->persist($attachment);

        return $attachment;
    }

    /** @return list<SocialMessage> */
    public function read(User $actor, SocialConversation $conversation, int $limit = 100): array
    {
        $this->assertEnabled();
        if (!$this->access->canReadConversation($actor, $conversation)) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Conversation membership is required.');
        }

        return $this->messages->forConversation($conversation, $limit);
    }

    public function markRead(User $actor, SocialConversation $conversation): void
    {
        $this->assertEnabled();
        $participant = $this->participants->activeFor($conversation, $actor);
        if ($participant === null) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Conversation membership is required.');
        }
        $participant->markRead(new \DateTimeImmutable());
    }

    public function leave(User $actor, SocialConversation $conversation): void
    {
        $this->assertEnabled();
        $participant = $this->participants->activeFor($conversation, $actor);
        if ($participant === null) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Conversation membership is required.');
        }
        $participant->leave(new \DateTimeImmutable());
    }

    public function pruneExpired(int $limit = 500): int
    {
        $this->assertEnabled();
        $count = 0;
        foreach ($this->messages->before(new \DateTimeImmutable('-30 days'), $limit) as $message) {
            if ($this->retention->isExpired($message, $message->getConversation()->getRetentionDays(), new \DateTimeImmutable())) {
                $message->redactForRetention(new \DateTimeImmutable());
                ++$count;
            }
        }

        return $count;
    }

    private function assertEnabled(): void
    {
        if (!$this->availability->enabled()) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }
    }

    private function sameUser(User $first, User $second): bool
    {
        return $first === $second || ($first->getId() !== null && $first->getId() === $second->getId());
    }
}
