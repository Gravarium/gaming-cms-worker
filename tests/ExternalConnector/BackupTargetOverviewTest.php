<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\BackupTargetOverview;
use App\ExternalConnector\OffsiteBackupTargetStatus;
use PHPUnit\Framework\TestCase;

final class BackupTargetOverviewTest extends TestCase
{
    public function testSummarizesHealthyFailedMissingAndStaleTargets(): void
    {
        $now = new \DateTimeImmutable('2026-09-18 18:00:00 UTC');
        $statuses = [
            'primary' => new OffsiteBackupTargetStatus('primary', '20260918T170000Z-abcdef1', $now->modify('-1 hour'), true, 1, true),
            'archive' => new OffsiteBackupTargetStatus('archive', '20260917T100000Z-abcdef1', $now->modify('-32 hours'), false, 3, false),
        ];

        $summary = (new BackupTargetOverview())->summarize([
            $this->target('primary', true, true),
            $this->target('archive', false, true),
            $this->target('missing', false, true),
            $this->target('disabled', true, false),
        ], $statuses, $now);

        self::assertSame(3, $summary['enabled']);
        self::assertSame(1, $summary['required']);
        self::assertSame(2, $summary['optional']);
        self::assertSame(1, $summary['healthy']);
        self::assertSame(1, $summary['failed']);
        self::assertSame(1, $summary['missing']);
        self::assertSame(1, $summary['stale']);
        self::assertSame('attention', $summary['overall']);
    }

    private function target(string $key, bool $required, bool $enabled): ExternalConnectorTarget
    {
        return (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_BACKUP)
            ->setTargetKey($key)
            ->setProviderKey('restic')
            ->setDisplayName($key)
            ->setConfigurationReference('backup.'.$key)
            ->setRequired($required)
            ->setEnabled($enabled);
    }
}
