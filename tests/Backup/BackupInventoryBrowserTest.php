<?php

declare(strict_types=1);

namespace App\Tests\Backup;

use App\Backup\BackupInventoryBrowser;
use App\Entity\BackupVerificationStatus;
use PHPUnit\Framework\TestCase;

final class BackupInventoryBrowserTest extends TestCase
{
    private BackupInventoryBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = new BackupInventoryBrowser();
    }

    public function testPaginationReturnsEveryBackupInStableNewestFirstPages(): void
    {
        $backups = [];
        $oldest = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');
        for ($index = 0; $index < 60; ++$index) {
            $createdAt = $oldest->modify(sprintf('+%d minutes', $index));
            $backups[] = $this->backup($index, $createdAt);
        }
        shuffle($backups);

        $criteria = $this->browser->normalizeRequest('', 'all', '1');
        $first = $this->browser->paginate($backups, [], $criteria['query'], $criteria['verification_filter'], $criteria['page']);
        $middle = $this->browser->paginate($backups, [], '', 'all', 2);
        $last = $this->browser->paginate($backups, [], '', 'all', 3);

        self::assertSame(60, $first['total_backups']);
        self::assertSame(3, $first['total_pages']);
        self::assertSame(25, $first['page_size']);
        self::assertSame([1, 25], [$first['first_result'], $first['last_result']]);
        self::assertSame([26, 50], [$middle['first_result'], $middle['last_result']]);
        self::assertSame([51, 60], [$last['first_result'], $last['last_result']]);
        self::assertSame($this->backupId(59), $first['backups'][0]['id']);
        self::assertSame($this->backupId(35), $first['backups'][24]['id']);
        self::assertSame($this->backupId(34), $middle['backups'][0]['id']);
        self::assertSame($this->backupId(10), $middle['backups'][24]['id']);
        self::assertSame($this->backupId(9), $last['backups'][0]['id']);
        self::assertSame($this->backupId(0), $last['backups'][9]['id']);
    }

    public function testSearchAndVerificationFilterCanBeCombinedWithoutChangingLatestSummary(): void
    {
        $oldest = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');
        $successful = $this->backup(1, $oldest->modify('+1 minute'), 'cafebabe0001');
        $failed = $this->backup(2, $oldest->modify('+2 minutes'), 'cafebabe0002');
        $unchecked = $this->backup(3, $oldest->modify('+3 minutes'), 'deadbeef0003');

        $statuses = [
            $successful['id'] => $this->verificationStatus($successful['id'], true),
            $failed['id'] => $this->verificationStatus($failed['id'], false),
        ];
        $criteria = $this->browser->normalizeRequest('CAFE', 'failed', '1');
        $filtered = $this->browser->paginate(
            [$successful, $failed, $unchecked],
            $statuses,
            $criteria['query'],
            $criteria['verification_filter'],
            $criteria['page'],
        );

        self::assertSame([$failed['id']], array_column($filtered['backups'], 'id'));
        self::assertSame(1, $filtered['total_backups']);
        self::assertSame($unchecked['id'], $filtered['latest_backup']['id'] ?? null);

        $unverified = $this->browser->paginate([$successful, $failed, $unchecked], $statuses, 'deadbeef', 'unchecked', 1);
        self::assertSame([$unchecked['id']], array_column($unverified['backups'], 'id'));

        $verified = $this->browser->paginate([$successful, $failed, $unchecked], $statuses, 'cafebabe', 'successful', 1);
        self::assertSame([$successful['id']], array_column($verified['backups'], 'id'));
    }

    public function testEqualTimestampsUseBackupIdAsDeterministicTieBreaker(): void
    {
        $createdAt = new \DateTimeImmutable('2026-09-01T00:00:00+00:00');
        $olderId = $this->backup(4, $createdAt);
        $newerId = $this->backup(5, $createdAt);

        $page = $this->browser->paginate([$olderId, $newerId], [], '', 'all', 1);

        self::assertSame([$newerId['id'], $olderId['id']], array_column($page['backups'], 'id'));
    }

    public function testEmptyInventoryHasOneEmptyPage(): void
    {
        $page = $this->browser->paginate([], [], '', 'all', 1);

        self::assertSame([], $page['backups']);
        self::assertSame(0, $page['total_backups']);
        self::assertSame(1, $page['total_pages']);
        self::assertSame([0, 0], [$page['first_result'], $page['last_result']]);
    }

    public function testRejectsMalformedSearchStatusAndPageValues(): void
    {
        $invalidRequests = [
            [[], 'all', '1'],
            ['<script>', 'all', '1'],
            [str_repeat('a', 81), 'all', '1'],
            ['', [], '1'],
            ['', 'unknown', '1'],
            ['', 'all', '0'],
            ['', 'all', '-1'],
            ['', 'all', '1000000'],
            ['', 'all', ['1']],
        ];

        foreach ($invalidRequests as [$query, $status, $page]) {
            try {
                $this->browser->normalizeRequest($query, $status, $page);
                self::fail('Malformed inventory query values must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRejectsPagesPastTheLastFilteredResult(): void
    {
        $backups = [
            $this->backup(1, new \DateTimeImmutable('2026-09-01T00:00:00+00:00')),
        ];

        $this->expectException(\OutOfRangeException::class);
        $this->browser->paginate($backups, [], '', 'all', 2);
    }

    /**
     * @return array{id: string, createdAt: \DateTimeImmutable, revision: string, bytes: int, objectStorage: string}
     */
    private function backup(int $index, \DateTimeImmutable $createdAt, string $revision = 'abcdef123456'): array
    {
        return [
            'id' => $this->backupId($index),
            'createdAt' => $createdAt,
            'revision' => $revision,
            'bytes' => 1024,
            'objectStorage' => 'not_configured',
        ];
    }

    private function backupId(int $index): string
    {
        return (new \DateTimeImmutable('2026-09-01T00:00:00+00:00'))
            ->modify(sprintf('+%d minutes', $index))
            ->format('Ymd\THis\Z').'-'.str_pad(dechex($index + 1), 12, '0', STR_PAD_LEFT);
    }

    private function verificationStatus(string $backupId, bool $successful): BackupVerificationStatus
    {
        return (new BackupVerificationStatus())
            ->setBackupId($backupId)
            ->setSuccessful($successful);
    }
}