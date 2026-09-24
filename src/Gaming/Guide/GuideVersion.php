<?php

declare(strict_types=1);

namespace App\Gaming\Guide;

final readonly class GuideVersion
{
    public function __construct(
        public string $gameVersion,
        public string $season,
        public \DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validUntil = null,
    ) {
        if (trim($gameVersion) === '' || mb_strlen($gameVersion) > 80 || mb_strlen($season) > 80) {
            throw new \InvalidArgumentException('A bounded game version and season are required.');
        }
        if ($validUntil !== null && $validUntil <= $validFrom) {
            throw new \InvalidArgumentException('Validity end must be after its start.');
        }
    }

    public function isOutdated(\DateTimeImmutable $now, string $currentGameVersion): bool
    {
        return $currentGameVersion !== $this->gameVersion
            || $now < $this->validFrom
            || ($this->validUntil !== null && $now > $this->validUntil);
    }
}
