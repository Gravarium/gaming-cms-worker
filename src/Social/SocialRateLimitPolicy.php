<?php

declare(strict_types=1);

namespace App\Social;

final class SocialRateLimitPolicy
{
    public const MAX_MESSAGE_LENGTH = 5000;
    public const MAX_GROUP_PARTICIPANTS = 20;
    public const MAX_MESSAGES_PER_HOUR = 60;
    public const MAX_RELATIONSHIP_REQUESTS_PER_DAY = 30;

    public function assertMessage(string $body): string
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > self::MAX_MESSAGE_LENGTH) {
            throw new \InvalidArgumentException('Messages must contain between 1 and 5000 characters.');
        }

        return $body;
    }

    public function assertGroupSize(int $participantCount): void
    {
        if ($participantCount < 2 || $participantCount > self::MAX_GROUP_PARTICIPANTS) {
            throw new \InvalidArgumentException('A group conversation needs between 2 and 20 participants.');
        }
    }

    public function assertMessageWindow(int $messagesInWindow): void
    {
        if ($messagesInWindow >= self::MAX_MESSAGES_PER_HOUR) {
            throw new \DomainException('The message rate limit has been reached.');
        }
    }

    public function assertRelationshipWindow(int $requestsInWindow): void
    {
        if ($requestsInWindow >= self::MAX_RELATIONSHIP_REQUESTS_PER_DAY) {
            throw new \DomainException('The relationship request rate limit has been reached.');
        }
    }
}
