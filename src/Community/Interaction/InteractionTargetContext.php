<?php

declare(strict_types=1);

namespace App\Community\Interaction;

final readonly class InteractionTargetContext
{
    public function __construct(
        public string $type,
        public int $id,
        public bool $moduleEnabled,
        public bool $publiclyVisible,
        public ?int $ownerUserId,
    ) {
        if ($this->type === '' || $this->id < 1) {
            throw new \InvalidArgumentException('Interaction target must have a type and positive id.');
        }
        if ($this->ownerUserId !== null && $this->ownerUserId < 1) {
            throw new \InvalidArgumentException('Target owner id must be positive.');
        }
    }

    public function ownedBy(InteractionActor $actor): bool
    {
        return $actor->userId !== null && $this->ownerUserId === $actor->userId;
    }
}
