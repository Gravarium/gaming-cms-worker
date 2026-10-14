<?php

declare(strict_types=1);

namespace App\Tests\ContentRedirect;

use App\ContentRedirect\ContentRedirectTargetAudit;
use App\Entity\ContentEntry;
use App\Entity\ContentRedirect;
use PHPUnit\Framework\TestCase;

final class ContentRedirectTargetAuditTest extends TestCase
{
    public function testOnlyUnpublishedTargetsAreReportedInStableOrder(): void
    {
        $audit = new ContentRedirectTargetAudit();
        $findings = $audit->findUnpublishedTargets([
            $this->redirect(ContentEntry::TYPE_PAGE, 'z-page', 'draft-page', false),
            $this->redirect(ContentEntry::TYPE_NEWS, 'old-news', 'live-news', true),
            $this->redirect(ContentEntry::TYPE_NEWS, 'old-draft', 'draft-news', false),
        ]);

        self::assertSame([
            ['type' => 'news', 'source_slug' => 'old-draft', 'target_slug' => 'draft-news'],
            ['type' => 'page', 'source_slug' => 'z-page', 'target_slug' => 'draft-page'],
        ], $findings);
    }

    public function testEmptyAndPublishedInventoriesHaveNoFindings(): void
    {
        $audit = new ContentRedirectTargetAudit();

        self::assertSame([], $audit->findUnpublishedTargets([]));
        self::assertSame([], $audit->findUnpublishedTargets([
            $this->redirect(ContentEntry::TYPE_NEWS, 'old', 'live', true),
        ]));
    }

    private function redirect(string $type, string $source, string $target, bool $published): ContentRedirect
    {
        $entry = (new ContentEntry())->setType($type)->setTitle($target)->setSlug($target);
        if ($published) {
            $entry->setStatus(ContentEntry::STATUS_PUBLISHED);
            $entry->synchronizePublication();
        }

        return new ContentRedirect($entry, $type, $source);
    }
}
