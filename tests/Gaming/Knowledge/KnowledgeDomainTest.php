<?php

declare(strict_types=1);

namespace App\Tests\Gaming\Knowledge;

use App\Gaming\Knowledge\CorrectionProposal;
use App\Gaming\Knowledge\KnowledgeRecord;
use App\Gaming\Knowledge\KnowledgeRelationship;
use App\Gaming\Knowledge\KnowledgeVisibilityPolicy;
use App\Gaming\Knowledge\MapMarker;
use App\Gaming\Knowledge\MapRoute;
use App\Gaming\Knowledge\PrivateChecklist;
use PHPUnit\Framework\TestCase;

final class KnowledgeDomainTest extends TestCase
{
    public function testVersionedRecordsRequireSourceAndLicence(): void
    {
        $record = new KnowledgeRecord(3, 'item', 'item.legendary_sword', 2, ['level' => 80], 'Publisher API documentation', 'Used with permission');
        self::assertSame(2, $record->version);
        self::assertSame('item', $record->type);
    }

    public function testTypedRelationshipsRejectSelfReferences(): void
    {
        $this->expectException(\DomainException::class);
        new KnowledgeRelationship(3, 'boss.one', 'drops', 'boss.one');
    }

    public function testMapGeometryIsBounded(): void
    {
        $marker = new MapMarker(3, 'collectibles', 'marker.1', 50.5, 10.25);
        $route = new MapRoute(3, 'route.daily', [['x' => 10.0, 'y' => 20.0], ['x' => 50.0, 'y' => 60.0]]);
        self::assertSame(50.5, $marker->x);
        self::assertCount(2, $route->points);
    }

    public function testChecklistIsStrictlyOwnerPrivate(): void
    {
        $checklist = new PrivateChecklist(3, 9);
        $checklist->set('item.one', true, 9);
        self::assertTrue($checklist->isCompleted('item.one', 9));
        $this->expectException(\DomainException::class);
        $checklist->isCompleted('item.one', 10);
    }

    public function testCorrectionRequiresIndependentModerator(): void
    {
        $proposal = new CorrectionProposal(3, 'item.one', 9, 'Correct the drop source', 'Official patch notes', 'Citation permitted');
        $proposal->decide(true, 10, 'Verified against patch notes');
        self::assertSame('accepted', $proposal->status());
        self::assertSame(10, $proposal->decisionEvidence()['reviewerId']);
    }

    public function testVisibilityFailsClosed(): void
    {
        $policy = new KnowledgeVisibilityPolicy();
        self::assertFalse($policy->canView('public', false, true, true));
        self::assertFalse($policy->canView('unknown', true, true, true));
        self::assertFalse($policy->canView('private', true, true, false));
        self::assertTrue($policy->canView('private', true, true, true));
    }
}
