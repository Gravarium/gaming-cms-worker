<?php

declare(strict_types=1);

namespace App\Widget\Module;

final readonly class ModuleWidgetViewer
{
    /** @var list<int> */
    public array $guildIds;

    /**
     * @param array<array-key, mixed> $guildIds
     */
    public function __construct(
        public bool $authenticated = false,
        public bool $moderator = false,
        public ?int $userId = null,
        public ?int $guildId = null,
        array $guildIds = [],
    ) {
        if (!$this->authenticated && ($this->moderator || $this->userId !== null || $this->guildId !== null || $guildIds !== [])) {
            throw new \InvalidArgumentException('Anonymous viewers cannot carry authenticated identity or guild data.');
        }
        if (!array_is_list($guildIds) || count($guildIds) > 500) {
            throw new \InvalidArgumentException('Viewer guild list must be a bounded list.');
        }
        $validatedGuildIds = [];
        foreach ($guildIds as $guildId) {
            if (!is_int($guildId) || $guildId < 1) {
                throw new \InvalidArgumentException('Viewer guild ids must be positive integers.');
            }
            $validatedGuildIds[] = $guildId;
        }
        if (count($validatedGuildIds) !== count(array_unique($validatedGuildIds))) {
            throw new \InvalidArgumentException('Viewer guild list must be unique.');
        }
        if ($this->userId !== null && $this->userId < 1) {
            throw new \InvalidArgumentException('Viewer user id must be positive.');
        }
        if ($this->guildId !== null && $this->guildId < 1) {
            throw new \InvalidArgumentException('Viewer guild id must be positive.');
        }
        $this->guildIds = $validatedGuildIds;
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
