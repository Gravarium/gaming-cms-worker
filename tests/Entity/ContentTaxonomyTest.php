<?php

declare(strict_types=1);
namespace App\Tests\Entity;
use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\ContentTag;
use PHPUnit\Framework\TestCase;
final class ContentTaxonomyTest extends TestCase
{
    public function testCategoryDisplayNameReflectsHierarchy(): void { $root=(new Category())->setName('Gaming'); $child=(new Category())->setName('World of Warships')->setParent($root); self::assertSame('Gaming / World of Warships', $child->getDisplayName()); }
    public function testCategoryRejectsSelfAndDescendantCycles(): void
    {
        $root = (new Category())->setName('Root');
        $child = (new Category())->setName('Child')->setParent($root);
        $grandchild = (new Category())->setName('Grandchild')->setParent($child);

        try {
            $root->setParent($grandchild);
            self::fail('Ancestor cycle was accepted.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(\DomainException::class);
        $child->setParent($child);
    }
    public function testTagsAreDeduplicatedOnEntry(): void { $tag=(new ContentTag())->setName('Update')->setSlug('update'); $entry=(new ContentEntry())->addTag($tag)->addTag($tag); self::assertCount(1,$entry->getTags()); $entry->removeTag($tag); self::assertCount(0,$entry->getTags()); }
}
