<?php

declare(strict_types=1);

namespace App\Tests\Gallery;

use App\Gallery\CompetitionVotes;
use App\Gallery\CompetitionWindow;
use App\Gallery\GallerySubmission;
use App\Gallery\GalleryVisibilityPolicy;
use App\Gallery\ManagedMediaReference;
use PHPUnit\Framework\TestCase;

final class GalleryDomainTest extends TestCase
{
    public function testSubmissionRequiresManagedMediaLicenceAndIndependentModeration(): void
    {
        $submission = new GallerySubmission(4, 12, 'Boss victory', 'CC BY 4.0', 'Player Four');
        $submission->moderate(true, 7, 'Licence and media ownership verified');
        self::assertSame('approved', $submission->status());
        self::assertSame(7, $submission->moderationEvidence()['moderatorId']);
    }

    public function testCompetitionWindowsDoNotOverlap(): void
    {
        $window = new CompetitionWindow(
            new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-10'),
            new \DateTimeImmutable('2026-10-10'), new \DateTimeImmutable('2026-10-20'),
        );
        self::assertTrue($window->submissionsOpen(new \DateTimeImmutable('2026-10-05')));
        self::assertTrue($window->votingOpen(new \DateTimeImmutable('2026-10-15')));
    }

    public function testOneAccountVoteAndAuditedCorrection(): void
    {
        $votes = new CompetitionVotes();
        $votes->cast(5, 20);
        self::assertSame(1, $votes->countFor(20));
        $votes->correct(5, 21, 8, 'Duplicate submission merged');
        self::assertSame(0, $votes->countFor(20));
        self::assertSame(1, $votes->countFor(21));
        self::assertCount(2, $votes->auditTrail());
    }

    public function testDuplicateVoteIsRejected(): void
    {
        $votes = new CompetitionVotes();
        $votes->cast(5, 20);
        $this->expectException(\DomainException::class);
        $votes->cast(5, 21);
    }

    public function testVisibilityAndDirectUrlFailClosed(): void
    {
        $policy = new GalleryVisibilityPolicy();
        self::assertFalse($policy->canView('public', false, true, true));
        self::assertFalse($policy->canView('private', true, true, false));
        self::assertTrue($policy->canView('private', true, true, true));
        new ManagedMediaReference(12, 4, 'gallery:submission:20');
        $this->expectException(\DomainException::class);
        ManagedMediaReference::fromExternalUrl('https://example.test/image.svg');
    }
}
