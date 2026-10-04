<?php

declare(strict_types=1);

namespace App\ContentSchedule;

final readonly class ContentScheduleEvent
{
    public const KIND_PUBLICATION = 'publication';
    public const KIND_UNPUBLICATION = 'unpublication';

    public function __construct(
        public int $entryId,
        public string $title,
        public string $kind,
        public \DateTimeImmutable $at,
    ) {
    }
}
