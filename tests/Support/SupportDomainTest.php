<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Appeal;
use App\Support\CaseAccessPolicy;
use App\Support\CaseEvidence;
use App\Support\RetentionPolicy;
use App\Support\Sanction;
use App\Support\SupportCase;
use PHPUnit\Framework\TestCase;

final class SupportDomainTest extends TestCase
{
    public function testCaseVisibilityIsParticipantOrAssignedStaffOnly(): void
    {
        $policy = new CaseAccessPolicy();
        self::assertTrue($policy->canView(4, [4, 5], false, true));
        self::assertFalse($policy->canView(6, [4, 5], false, true));
        self::assertFalse($policy->canView(4, [4], true, false));
    }

    public function testCaseTransitionsAreAuditedAndConcurrencySafe(): void
    {
        $case = new SupportCase(4, 'Private report', new \DateTimeImmutable('2026-10-01'));
        $case->assign(8, 9, 0, new \DateTimeImmutable('2026-09-24'));
        $case->transition('investigating', 8, 'Evidence review started', 1, new \DateTimeImmutable('2026-09-24'));
        self::assertSame('investigating', $case->state());
        self::assertCount(2, $case->history());
        $this->expectException(\DomainException::class);
        $case->transition('closed', 8, 'Stale write', 1, new \DateTimeImmutable('2026-09-24'));
    }

    public function testEvidenceExportRedactsManagedReference(): void
    {
        $evidence = new CaseEvidence(4, 'media', 12, 'Screenshot evidence', str_repeat('a', 64));
        self::assertNull($evidence->export(true, false)['referenceId']);
        self::assertSame(12, $evidence->export(true, true)['referenceId']);
    }

    public function testSanctionExpiryAndIndependentAppeal(): void
    {
        $sanction = new Sanction(4, 'suspension', 'rules:4.2', 'Repeated harassment', new \DateTimeImmutable('2026-09-24'), new \DateTimeImmutable('2026-10-01'));
        self::assertTrue($sanction->isActive(new \DateTimeImmutable('2026-09-25')));
        self::assertFalse($sanction->isActive(new \DateTimeImmutable('2026-10-02')));
        $appeal = new Appeal(4, 10, 'Relevant evidence was not considered.');
        $appeal->decide(true, 8, 'Evidence reviewed; sanction revoked.');
        self::assertSame('upheld', $appeal->state());
    }

    public function testRetentionHonoursLegalHold(): void
    {
        $policy = new RetentionPolicy();
        $closed = new \DateTimeImmutable('2025-01-01');
        self::assertTrue($policy->deletionDue($closed, new \DateTimeImmutable('2026-02-01'), false));
        self::assertFalse($policy->deletionDue($closed, new \DateTimeImmutable('2026-02-01'), true));
    }
}
