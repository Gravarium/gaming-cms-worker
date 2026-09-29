<?php

declare(strict_types=1);

namespace App\ContentReview;

use Symfony\Component\Validator\Constraints as Assert;

final class ReviewDecision
{
    public const ACTION_PUBLISH = 'publish';
    public const ACTION_SCHEDULE = 'schedule';
    public const ACTION_REQUEST_CHANGES = 'request_changes';

    #[Assert\Choice(choices: [self::ACTION_PUBLISH, self::ACTION_SCHEDULE, self::ACTION_REQUEST_CHANGES])]
    public ?string $decision = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 300)]
    public string $reason = '';

    public ?\DateTimeImmutable $scheduledAt = null;
}
