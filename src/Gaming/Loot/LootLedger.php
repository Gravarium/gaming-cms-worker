<?php

declare(strict_types=1);

namespace App\Gaming\Loot;

final class LootLedger
{
    /** @var list<LootLedgerEntry> */
    private array $entries = [];
    /** @var array<string, true> */
    private array $ids = [];
    private int $version = 0;

    public function __construct(private readonly int $guildId)
    {
        if ($guildId < 1) {
            throw new \InvalidArgumentException('Guild is required.');
        }
    }

    public function append(LootLedgerEntry $entry, int $expectedVersion): void
    {
        if ($expectedVersion !== $this->version) {
            throw new \DomainException('Concurrent ledger modification detected.');
        }
        if ($entry->guildId !== $this->guildId) {
            throw new \DomainException('Cross-guild ledger entry denied.');
        }
        if (isset($this->ids[$entry->id])) {
            throw new \DomainException('Duplicate ledger identity denied.');
        }
        if ($entry->correctsEntryId !== null && !isset($this->ids[$entry->correctsEntryId])) {
            throw new \DomainException('Correction must reference an existing ledger entry.');
        }
        $this->entries[] = $entry;
        $this->ids[$entry->id] = true;
        ++$this->version;
    }

    public function correction(string $id, string $originalId, int $actorId, string $reason, int $expectedVersion): void
    {
        $original = null;
        foreach ($this->entries as $entry) {
            if ($entry->id === $originalId) {
                $original = $entry;
                break;
            }
        }
        if ($original === null) {
            throw new \DomainException('Unknown entry cannot be corrected.');
        }
        $this->append(new LootLedgerEntry(
            $id,
            $this->guildId,
            $original->memberId,
            -$original->amount,
            $original->currency,
            $reason,
            $actorId,
            new \DateTimeImmutable(),
            $original->raidReference,
            $originalId,
        ), $expectedVersion);
    }

    public function balance(int $memberId, string $currency): int
    {
        return array_sum(array_map(
            static fn (LootLedgerEntry $entry): int => $entry->memberId === $memberId && $entry->currency === $currency ? $entry->amount : 0,
            $this->entries,
        ));
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @return list<LootLedgerEntry> */
    public function export(): array
    {
        return $this->entries;
    }
}
