<?php

declare(strict_types=1);

namespace App\Tests\Gaming\Catalogue;

use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameEdition;
use App\Entity\GameCatalogue\GameGenre;
use App\Entity\GameCatalogue\GameHubLink;
use App\Entity\GameCatalogue\GamePlatform;
use App\Entity\GameCatalogue\GamePublisher;
use App\Entity\GameCatalogue\GameRelease;
use PHPUnit\Framework\TestCase;

final class GameCatalogueCoreTest extends TestCase
{
    public function testCatalogueVisibilityFollowsEntryAndBaseGame(): void
    {
        $game=(new Game())->setName('Alpha')->setSlug('alpha')->setEnabled(true);
        $entry=(new GameCatalogueEntry($game))->setSummary('Summary')->setDeveloper('Studio')->setPublisher(new GamePublisher('Publisher','publisher'))->addGenre(new GameGenre('RPG','rpg'));
        self::assertTrue($entry->isPublic());

        $game->setEnabled(false);
        self::assertFalse($entry->isPublic());
    }

    public function testReleaseRejectsEditionFromAnotherGame(): void
    {
        $platform=new GamePlatform('PC','pc');
        $entryA=new GameCatalogueEntry((new Game())->setName('A')->setSlug('a'));
        $entryB=new GameCatalogueEntry((new Game())->setName('B')->setSlug('b'));
        $release=new GameRelease($entryA,$platform,'EU',new \DateTimeImmutable('+1 month'));

        $this->expectException(\DomainException::class);
        $release->setEdition(new GameEdition($entryB,'Deluxe'));
    }

    public function testHubLinksOnlyAcceptKnownTargets(): void
    {
        $entry=new GameCatalogueEntry((new Game())->setName('A')->setSlug('a'));
        $link=new GameHubLink($entry,'video',12,'Trailer');
        self::assertSame('video',$link->getTargetType());

        $this->expectException(\InvalidArgumentException::class);
        new GameHubLink($entry,'private-secret',1,'No');
    }

    public function testReleaseStatusIsBounded(): void
    {
        $entry=new GameCatalogueEntry((new Game())->setName('A')->setSlug('a'));
        $release=new GameRelease($entry,new GamePlatform('PC','pc'),'EU',new \DateTimeImmutable('+1 month'));
        $release->setStatus('delayed');
        self::assertSame('delayed',$release->getStatus());

        $this->expectException(\InvalidArgumentException::class);
        $release->setStatus('hidden-backdoor');
    }
}
