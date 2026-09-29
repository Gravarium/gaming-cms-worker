<?php

declare(strict_types=1);

namespace App\Social;

use App\Entity\Social\SocialConversation;
use App\Entity\Social\SocialConversationParticipant;
use App\Entity\User;
use App\Repository\Social\SocialConversationParticipantRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class SocialConversationMembershipService
{
    public function __construct(
        private SocialModuleAvailability $availability,
        private SocialAccessPolicy $access,
        private SocialConversationRecipientQuery $recipientQuery,
        private SocialRateLimitPolicy $rateLimits,
        private SocialConversationParticipantRepository $participants,
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<SocialConversationParticipant> */
    public function activeParticipants(User $actor, SocialConversation $conversation): array
    {
        $this->assertEnabled();
        $this->assertGroupConversation($conversation);
        if (!$this->access->canReadConversation($actor, $conversation)) {
            throw new AccessDeniedException('Conversation membership is required.');
        }

        return $this->participants->activeParticipants($conversation);
    }

    public function canManage(User $actor, SocialConversation $conversation): bool
    {
        $this->assertEnabled();

        return $conversation->isGroup() && $this->access->canManageConversation($actor, $conversation);
    }

    /** @return array<string, int> */
    public function recipientChoices(User $actor): array
    {
        $this->assertEnabled();

        return $this->recipientQuery->choicesFor($actor);
    }

    /** @param list<mixed> $rawRecipientIds */
    public function addMembers(User $actor, SocialConversation $conversation, array $rawRecipientIds): void
    {
        $this->assertEnabled();

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $conversation, $rawRecipientIds): void {
            $entityManager->refresh($conversation, LockMode::PESSIMISTIC_WRITE);
            $this->assertGroupConversation($conversation);
            $this->assertCanManage($actor, $conversation);

            $recipientIds = $this->normalizeRecipientIds($rawRecipientIds);
            if ($recipientIds === []) {
                throw new \InvalidArgumentException('Wähle mindestens ein Mitglied aus.');
            }

            $visibleIds = [];
            foreach ($this->recipientQuery->choicesFor($actor) as $visibleId) {
                if (is_int($visibleId)) {
                    $visibleIds[$visibleId] = true;
                }
            }

            $existingParticipants = $this->participants->activeParticipants($conversation);
            $existingUsers = array_map(
                static fn (SocialConversationParticipant $participant): User => $participant->getUser(),
                $existingParticipants,
            );
            $newUsers = [];
            $existingRows = [];

            foreach ($recipientIds as $recipientId) {
                if (!isset($visibleIds[$recipientId])) {
                    throw new \DomainException('Ein ausgewähltes Mitglied ist nicht mehr verfügbar.');
                }

                $recipient = $this->users->find($recipientId);
                if (!$recipient instanceof User || !$recipient->isActive()) {
                    throw new \DomainException('Ein ausgewähltes Mitglied ist nicht mehr verfügbar.');
                }

                if ($this->sameUser($actor, $recipient)) {
                    throw new \DomainException('Du bist bereits Mitglied dieser Unterhaltung.');
                }

                $existing = $this->participants->findOneBy([
                    'conversation' => $conversation,
                    'user' => $recipient,
                ]);
                if ($existing instanceof SocialConversationParticipant && $existing->isActive()) {
                    throw new \DomainException('Ein ausgewähltes Mitglied ist bereits in der Gruppe.');
                }

                $newUsers[$recipientId] = $recipient;
                $existingRows[$recipientId] = $existing instanceof SocialConversationParticipant ? $existing : null;
            }

            $this->rateLimits->assertGroupSize(count($existingParticipants) + count($newUsers));
            $allUsers = [...$existingUsers, ...array_values($newUsers)];
            foreach ($newUsers as $newUser) {
                foreach ($allUsers as $otherUser) {
                    if ($this->sameUser($newUser, $otherUser)) {
                        continue;
                    }
                    if (
                        !$this->access->canStartConversation($otherUser, $newUser)
                        || !$this->access->canStartConversation($newUser, $otherUser)
                    ) {
                        throw new \DomainException('Die Mitglieder können nach ihren Social-Privatsphäre- oder Block-Einstellungen nicht gemeinsam schreiben.');
                    }
                }
            }

            $joinedAt = new \DateTimeImmutable();
            foreach ($newUsers as $recipientId => $recipient) {
                $existing = $existingRows[$recipientId];
                if ($existing instanceof SocialConversationParticipant) {
                    $existing->rejoin($joinedAt);
                    continue;
                }

                $entityManager->persist(new SocialConversationParticipant(
                    $conversation,
                    $recipient,
                    SocialConversationParticipant::ROLE_MEMBER,
                ));
            }
        });
    }

    public function removeMember(User $actor, SocialConversation $conversation, int $participantId): void
    {
        $this->assertEnabled();

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $conversation, $participantId): void {
            $entityManager->refresh($conversation, LockMode::PESSIMISTIC_WRITE);
            $this->assertGroupConversation($conversation);
            $this->assertCanManage($actor, $conversation);

            $participant = $this->participants->find($participantId);
            if (
                !$participant instanceof SocialConversationParticipant
                || $participant->getConversation()->getId() !== $conversation->getId()
            ) {
                throw new NotFoundHttpException();
            }
            if (!$participant->isActive()) {
                throw new \DomainException('This participant is no longer active.');
            }
            if ($participant->isOwner()) {
                throw new \DomainException('A group owner cannot be removed; use the leave action to transfer ownership.');
            }
            if ($this->sameUser($actor, $participant->getUser())) {
                throw new \DomainException('Use the leave action to leave your own group.');
            }

            $participant->remove(new \DateTimeImmutable());
        });
    }

    public function leave(User $actor, SocialConversation $conversation): bool
    {
        $this->assertEnabled();

        return $this->entityManager->wrapInTransaction(function (EntityManagerInterface $entityManager) use ($actor, $conversation): bool {
            $entityManager->refresh($conversation, LockMode::PESSIMISTIC_WRITE);
            if (!$this->access->canReadConversation($actor, $conversation)) {
                throw new AccessDeniedException('Conversation membership is required.');
            }

            $participant = $this->participants->activeFor($conversation, $actor);
            if (!$participant instanceof SocialConversationParticipant) {
                throw new AccessDeniedException('Conversation membership is required.');
            }

            $transferredOwnership = false;
            if ($conversation->isGroup() && $participant->isOwner()) {
                foreach ($this->participants->activeParticipants($conversation) as $candidate) {
                    if ($candidate->getId() === $participant->getId()) {
                        continue;
                    }
                    $candidate->promoteToOwner();
                    $transferredOwnership = true;
                    break;
                }
            }

            $participant->leave(new \DateTimeImmutable());

            return $transferredOwnership;
        });
    }

    private function assertCanManage(User $actor, SocialConversation $conversation): void
    {
        if (!$this->access->canManageConversation($actor, $conversation)) {
            throw new AccessDeniedException('Only an active group owner or administrator can manage members.');
        }
    }

    private function assertGroupConversation(SocialConversation $conversation): void
    {
        if (!$conversation->isGroup() || $conversation->isDeleted()) {
            throw new NotFoundHttpException();
        }
    }

    /** @param list<mixed> $rawIds
     *  @return list<int>
     */
    private function normalizeRecipientIds(array $rawIds): array
    {
        $ids = [];
        foreach ($rawIds as $rawId) {
            if (is_int($rawId) && $rawId > 0) {
                $id = $rawId;
            } elseif (is_string($rawId) && preg_match('/^[1-9][0-9]{0,9}$/D', $rawId) === 1) {
                $id = (int) $rawId;
            } else {
                throw new \InvalidArgumentException('Die Mitgliederauswahl ist ungültig.');
            }
            if (isset($ids[$id])) {
                throw new \InvalidArgumentException('Mitglieder können nur einmal ausgewählt werden.');
            }
            $ids[$id] = $id;
        }

        return array_values($ids);
    }

    private function sameUser(User $first, User $second): bool
    {
        return $first === $second || ($first->getId() !== null && $first->getId() === $second->getId());
    }

    private function assertEnabled(): void
    {
        if (!$this->availability->enabled()) {
            throw new NotFoundHttpException();
        }
    }
}
