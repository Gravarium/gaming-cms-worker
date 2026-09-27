<?php

declare(strict_types=1);

namespace App\Gaming\Loot;

final readonly class LootLedgerEntry
{
    public function __construct(
        public string $id,
        public int $guildId,
        public int $memberId,
        public int $amount,
        public string $currency,
        public string $reason,
        public int $actorId,
        public \DateTimeImmutable $occurredAt,
        public ?string $raidReference = null,
        public ?string $correctsEntryId = null,
    ) {
        if ($id === '' || $guildId < 1 || $memberId < 1 || $actorId < 1 || $amount === 0) {
            throw new \InvalidArgumentException('Ledger identity, actors and non-zero amount are required.');
        }
        if (!in_array($currency, ['dkp', 'ep', 'gp'], true)) {
            throw new \InvalidArgumentException('Unsupported ledger currency.');
        }
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException('A bounded audit reason is required.');
        }
    }
}
