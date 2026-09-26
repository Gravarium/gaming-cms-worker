<?php

declare(strict_types=1);

namespace App\Social;

use App\Entity\Social\SocialAttachment;
use App\Entity\Social\SocialConversation;
use App\Entity\Social\SocialConversationParticipant;
use App\Entity\Social\SocialMessage;
use App\Entity\Social\SocialPrivacySettings;
use App\Entity\User;
use App\Repository\Social\SocialBlockRepository;
use App\Repository\Social\SocialConversationParticipantRepository;
use App\Repository\Social\SocialPrivacySettingsRepository;
use App\Repository\Social\SocialRelationshipRepository;

final readonly class SocialAccessPolicy
{
    public function __construct(
        private SocialConversationParticipantRepository $participants,
        private SocialBlockRepository $blocks,
        private SocialRelationshipRepository $relationships,
        private SocialPrivacySettingsRepository $privacy,
    ) {
    }

    public function canReadConversation(User $actor, SocialConversation $conversation): bool
    {
        return $actor->isActive()
            && !$conversation->isDeleted()
            && $this->participants->activeFor($conversation, $actor) instanceof SocialConversationParticipant;
    }

    public function canWriteConversation(User $actor, SocialConversation $conversation): bool
    {
        if (!$this->canReadConversation($actor, $conversation)) {
            return false;
        }

        foreach ($this->participants->activeParticipants($conversation) as $participant) {
            $other = $participant->getUser();
            if (!$this->sameUser($actor, $other) && $this->blocks->isBlockedEitherDirection($actor, $other)) {
                return false;
            }
        }

        return true;
    }

    public function canAccessMessage(User $actor, SocialMessage $message): bool
    {
        return $message->getConversation() instanceof SocialConversation
            && $this->canReadConversation($actor, $message->getConversation());
    }

    public function canAccessAttachment(User $actor, SocialAttachment $attachment): bool
    {
        return $attachment->isAccessible() && $this->canAccessMessage($actor, $attachment->getMessage());
    }

    public function canStartConversation(User $sender, User $recipient): bool
    {
        return $sender->isActive()
            && $recipient->isActive()
            && !$this->sameUser($sender, $recipient)
            && !$this->blocks->isBlockedEitherDirection($sender, $recipient)
            && $this->canReceiveMessage($recipient, $sender);
    }

    public function canReceiveMessage(User $recipient, User $sender): bool
    {
        if (!$recipient->isActive() || !$sender->isActive() || $this->sameUser($recipient, $sender)) {
            return false;
        }
        if ($this->blocks->isBlockedEitherDirection($recipient, $sender)) {
            return false;
        }

        $settings = $recipient->getId() === null ? new SocialPrivacySettings($recipient) : $this->privacy->forUser($recipient);
        $policy = $settings?->getMessagePolicy() ?? SocialPrivacySettings::MESSAGE_EVERYONE;
        if ($policy === SocialPrivacySettings::MESSAGE_NOBODY) {
            return false;
        }
        if ($policy === SocialPrivacySettings::MESSAGE_RELATIONSHIPS) {
            return $this->relationships->acceptedBetween($recipient, $sender);
        }

        return true;
    }

    public function canManageConversation(User $actor, SocialConversation $conversation): bool
    {
        if (!$this->canReadConversation($actor, $conversation)) {
            return false;
        }

        if ($actor->isAdmin()) {
            return true;
        }

        $participant = $this->participants->activeFor($conversation, $actor);

        return $participant?->isOwner() ?? false;
    }

    private function sameUser(User $first, User $second): bool
    {
        return $first === $second || ($first->getId() !== null && $first->getId() === $second->getId());
    }
}
