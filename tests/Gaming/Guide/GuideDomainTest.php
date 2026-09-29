<?php

declare(strict_types=1);

namespace App\Tests\Gaming\Guide;

use App\Gaming\Guide\BuildComponent;
use App\Gaming\Guide\GuideReview;
use App\Gaming\Guide\GuideVersion;
use App\Gaming\Guide\GuideVisibilityPolicy;
use App\Gaming\Guide\StructuredBuild;
use App\Gaming\Guide\TierList;
use PHPUnit\Framework\TestCase;

final class GuideDomainTest extends TestCase
{
    public function testPatchValidityMakesStaleContentVisible(): void
    {
        $version = new GuideVersion('1.2.0', 'Season 4', new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'));
        self::assertFalse($version->isOutdated(new \DateTimeImmutable('2026-06-01'), '1.2.0'));
        self::assertTrue($version->isOutdated(new \DateTimeImmutable('2026-06-01'), '1.3.0'));
    }

    public function testStructuredBuildRoundTripsWithoutExecutableInput(): void
    {
        $build = new StructuredBuild();
        $build->add(new BuildComponent('skill', 'skill.fireball', 1, ['skill.frostbolt']));
        $build->add(new BuildComponent('rotation', 'rotation.opener', 2));
        $imported = StructuredBuild::importCode($build->exportCode());
        self::assertCount(2, $imported->components());
        self::assertSame('skill.fireball', $imported->components()[0]->key);
    }

    public function testTierListRequiresCriteriaProvenanceAndReasons(): void
    {
        $list = new TierList('Single-target performance in patch 1.2', 'Synthetic benchmark set v3');
        $list->rank('class.mage', 'A', 'Strong sustained output');
        self::assertSame('A', $list->entries()['class.mage']['tier']);
    }

    public function testAuthorCannotSelfApprove(): void
    {
        $review = new GuideReview();
        $review->submit(7, new \DateTimeImmutable('2026-09-24T10:00:00Z'));
        $this->expectException(\DomainException::class);
        $review->approve(7, 7, 'Self approval', new \DateTimeImmutable('2026-09-24T11:00:00Z'));
    }

    public function testReviewAuditAndVisibilityFailClosed(): void
    {
        $review = new GuideReview();
        $review->submit(7, new \DateTimeImmutable('2026-09-24T10:00:00Z'));
        $review->approve(8, 7, 'Checked against current patch', new \DateTimeImmutable('2026-09-24T11:00:00Z'));
        self::assertSame('published', $review->status());
        self::assertCount(2, $review->history());
        $policy = new GuideVisibilityPolicy();
        self::assertTrue($policy->canRead(true, 'published', false));
        self::assertFalse($policy->canRead(false, 'published', true));
        self::assertFalse($policy->canRead(true, 'unknown', false));
    }
}
