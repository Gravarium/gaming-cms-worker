<?php

declare(strict_types=1);

namespace App\Tests\Gaming\Loot;

use App\Gaming\Loot\GuildLootAccessPolicy;
use App\Gaming\Loot\GuildVault;
use App\Gaming\Loot\LootAwardPolicy;
use App\Gaming\Loot\LootLedger;
use App\Gaming\Loot\LootLedgerEntry;
use App\Gaming\Loot\Wishlist;
use PHPUnit\Framework\TestCase;

final class LootDomainTest extends TestCase
{
    public function testLedgerIsAppendOnlyAndCorrectionsCompensate(): void
    {
        $ledger = new LootLedger(4);
        $ledger->append(new LootLedgerEntry('award-1', 4, 8, 25, 'dkp', 'Raid attendance', 2, new \DateTimeImmutable(), 'raid-7'), 0);
        self::assertSame(25, $ledger->balance(8, 'dkp'));
        $ledger->correction('correction-1', 'award-1', 3, 'Approved mistaken award reversal', 1);
        self::assertSame(0, $ledger->balance(8, 'dkp'));
        self::assertCount(2, $ledger->export());
    }

    public function testCrossGuildAndConcurrentWritesFailClosed(): void
    {
        $ledger = new LootLedger(4);
        $entry = new LootLedgerEntry('award-1', 5, 8, 25, 'dkp', 'Wrong guild', 2, new \DateTimeImmutable());
        $this->expectException(\DomainException::class);
        $ledger->append($entry, 0);
    }

    public function testStaleVersionIsRejected(): void
    {
        $ledger = new LootLedger(4);
        $ledger->append(new LootLedgerEntry('one', 4, 8, 10, 'ep', 'Attendance', 2, new \DateTimeImmutable()), 0);
        $this->expectException(\DomainException::class);
        $ledger->append(new LootLedgerEntry('two', 4, 8, 10, 'ep', 'Boss', 2, new \DateTimeImmutable()), 0);
    }

    public function testStrategiesWishlistAndVaultAreBounded(): void
    {
        $policy = new LootAwardPolicy();
        self::assertSame(2.5, $policy->score('epgp', ['ep' => 100, 'gp' => 40]));
        self::assertSame(87.0, $policy->score('roll', [], 0, 87));
        $wishlist = new Wishlist(4, 8);
        $wishlist->wish('item.sword', 5);
        self::assertSame(5, $wishlist->priority('item.sword'));
        $vault = new GuildVault(4);
        $vault->adjust('item.flask', 20, 2, 'Raid preparation deposit');
        $vault->adjust('item.flask', -3, 2, 'Raid distribution');
        self::assertSame(17, $vault->quantity('item.flask'));
        self::assertCount(2, $vault->auditTrail());
    }

    public function testApprovalRequiresSameGuildOfficerAndEnabledModule(): void
    {
        $policy = new GuildLootAccessPolicy();
        self::assertTrue($policy->canApprove(4, 4, true, true));
        self::assertFalse($policy->canApprove(4, 5, true, true));
        self::assertFalse($policy->canApprove(4, 4, false, true));
        self::assertFalse($policy->canApprove(4, 4, true, false));
    }
}
