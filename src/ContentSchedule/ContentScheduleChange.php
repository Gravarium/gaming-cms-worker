<?php

declare(strict_types=1);

namespace App\ContentSchedule;

use Symfony\Component\Validator\Constraints as Assert;

final class ContentScheduleChange
{
    #[Assert\NotNull]
    public ?\DateTimeImmutable $scheduledAt = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 300)]
    public string $reason = '';

    #[Assert\NotBlank]
    public string $expectedAt = '';
}
