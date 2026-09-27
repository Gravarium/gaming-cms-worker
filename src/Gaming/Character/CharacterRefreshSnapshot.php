<?php

declare(strict_types=1);

namespace App\Gaming\Character;

final readonly class CharacterRefreshSnapshot
{
    /**
     * @param array<string, mixed> $builds
     * @param array<string, mixed> $professions
     * @param array<string, mixed> $progression
     * @param array<string, mixed> $collections
     */
    public function __construct(
        public string $provider,
        public string $externalId,
        public string $name,
        public ?string $server,
        public ?string $region,
        public ?string $characterClass,
        public ?string $role,
        public ?int $level,
        public array $builds,
        public array $professions,
        public array $progression,
        public array $collections,
        public string $payloadHash,
        public \DateTimeImmutable $observedAt,
    ) {
        if (trim($this->provider) === '' || mb_strlen($this->provider) > 80) {
            throw new \InvalidArgumentException('Import provider is required and bounded.');
        }
        if (trim($this->externalId) === '' || mb_strlen($this->externalId) > 160) {
            throw new \InvalidArgumentException('External character identity is required and bounded.');
        }
        if (trim($this->name) === '' || mb_strlen($this->name) > 160) {
            throw new \InvalidArgumentException('Imported character name is required and bounded.');
        }
        if (trim($this->payloadHash) === '' || mb_strlen($this->payloadHash) > 128) {
            throw new \InvalidArgumentException('Import payload hash is required and bounded.');
        }
        if ($this->level !== null && ($this->level < 1 || $this->level > 10000)) {
            throw new \InvalidArgumentException('Character level must be between 1 and 10000.');
        }
    }
}
