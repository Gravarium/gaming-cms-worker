<?php

declare(strict_types=1);

namespace App\Social;

use App\Entity\Social\SocialRelationship;
use App\Entity\User;
use App\Repository\Social\SocialBlockRepository;
use App\Repository\Social\SocialConversationParticipantRepository;
use App\Repository\Social\SocialMessageRepository;
use App\Repository\Social\SocialRelationshipRepository;

final readonly class SocialDataRightsService
{
    public function __construct(
        private SocialConversationParticipantRepository $participants,
        private SocialMessageRepository $messages,
        private SocialRelationshipRepository $relationships,
        private SocialBlockRepository $blocks,
    ) {
    }

    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        $messages = [];
        foreach ($this->messages->forAuthor($user) as $message) {
            $messages[] = [
                'id' => $message->getId(),
                'conversationId' => $message->getConversation()->getId(),
                'body' => $message->getBody(),
                'deleted' => $message->isDeleted(),
                'createdAt' => $message->getCreatedAt()->format(DATE_ATOM),
            ];
        }

        $relationships = [];
        foreach ($this->relationships->forUser($user) as $relationship) {
            $relationships[] = [
                'id' => $relationship->getId(),
                'type' => $relationship->getType(),
                'state' => $relationship->getState(),
                'requesterId' => $relationship->getRequester()->getId(),
                'recipientId' => $relationship->getRecipient()->getId(),
            ];
        }

        $blocks = [];
        foreach ($this->blocks->activeFor($user) as $block) {
            $blocks[] = [
                'id' => $block->getId(),
                'blockedUserId' => $block->getBlocker() === $user ? $block->getBlocked()->getId() : $block->getBlocker()->getId(),
            ];
        }

        return [
            'schema' => 'social-data-export-v1',
            'userId' => $user->getId(),
            'exportedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'messages' => $messages,
            'relationships' => $relationships,
            'blocks' => $blocks,
            'conversationMembershipCount' => count($this->participants->activeForUser($user)),
        ];
    }

    /** @return array{messages:int,relationships:int,blocks:int,conversations:int} */
    public function anonymize(User $user, \DateTimeImmutable $at): array
    {
        $messages = 0;
        foreach ($this->messages->forAuthor($user) as $message) {
            if (!$message->isDeleted()) {
                $message->redactForRetention($at);
                ++$messages;
            }
        }

        $relationships = 0;
        foreach ($this->relationships->forUser($user) as $relationship) {
            if (!$relationship->isPending()) {
                continue;
            }
            if ($relationship->getRequester() === $user || $relationship->getRequester()->getId() === $user->getId()) {
                $relationship->cancel($at);
            } else {
                $relationship->reject($at);
            }
            ++$relationships;
        }

        $blocks = 0;
        foreach ($this->blocks->activeFor($user, $at) as $block) {
            $block->lift($at);
            ++$blocks;
        }

        $conversations = 0;
        foreach ($this->participants->activeForUser($user) as $participant) {
            $participant->leave($at);
            ++$conversations;
        }

        return compact('messages', 'relationships', 'blocks', 'conversations');
    }
}
