<?php

declare(strict_types=1);

namespace App\Gallery;

final class GallerySubmission
{
    private string $status = 'pending';
    private ?int $moderatorId = null;
    private ?string $decisionReason = null;

    public function __construct(
        public readonly int $ownerId,
        public readonly int $mediaAssetId,
        public readonly string $title,
        public readonly string $license,
        public readonly string $credit,
        public readonly string $visibility = 'public',
    ) {
        if ($ownerId < 1 || $mediaAssetId < 1 || trim($title) === '' || mb_strlen($title) > 180) {
            throw new \InvalidArgumentException('Submission owner, managed media and bounded title are required.');
        }
        if (trim($license) === '' || trim($credit) === '' || mb_strlen($license) > 160 || mb_strlen($credit) > 300) {
            throw new \InvalidArgumentException('Licence and credit are required.');
        }
        if (!in_array($visibility, ['public', 'members', 'private'], true)) {
            throw new \InvalidArgumentException('Unknown submission visibility.');
        }
    }

    public function moderate(bool $approved, int $moderatorId, string $reason): void
    {
        if ($this->status !== 'pending' || $moderatorId < 1 || $moderatorId === $this->ownerId || trim($reason) === '') {
            throw new \DomainException('Independent final moderation decision required.');
        }
        $this->status = $approved ? 'approved' : 'rejected';
        $this->moderatorId = $moderatorId;
        $this->decisionReason = $reason;
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return array{moderatorId: int|null, reason: string|null} */
    public function moderationEvidence(): array
    {
        return ['moderatorId' => $this->moderatorId, 'reason' => $this->decisionReason];
    }
}
