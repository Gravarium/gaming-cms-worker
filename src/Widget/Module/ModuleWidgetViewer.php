<?php

declare(strict_types=1);

namespace App\Widget\Module;

final readonly class ModuleWidgetViewer
{
    /**
     * @param list<int> $guildIds
     */
    public function __construct(
        public bool $authenticated = false,
        public bool $moderator = false,
        public ?int $userId = null,
        public ?int $guildId = null,
        public array $guildIds = [],
    ) {
        if ($this->userId !== null && $this->userId < 1) {
            throw new \InvalidArgumentException('Viewer user id must be positive.');
        }
        if ($this->guildId !== null && $this->guildId < 1) {
            throw new \InvalidArgumentException('Viewer guild id must be positive.');
        }
        foreach ($this->guildIds as $guildId) {
            if ($guildId < 1) {
                throw new \InvalidArgumentException('Viewer guild ids must be positive.');
            }
        }
    }

    public static function anonymous(): self
    {
        return new self();
    }

    public function canAccess(string $visibility, ?int $resourceGuildId = null): bool
    {
        return match ($visibility) {
            'public' => true,
            'authenticated' => $this->authenticated,
            'moderator' => $this->authenticated && $this->moderator,
            'guild' => $this->authenticated
                && $resourceGuildId !== null
                && in_array($resourceGuildId, $this->guildIds(), true),
            default => false,
        };
    }

    /** @return list<int> */
    public function guildIds(): array
    {
        $ids = $this->guildIds;
        if ($this->guildId !== null) {
            $ids[] = $this->guildId;
        }

        return array_values(array_unique($ids));
    }
}
