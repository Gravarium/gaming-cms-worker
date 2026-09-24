<?php

declare(strict_types=1);

namespace App\Tests\Gaming\Catalogue;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameEdition;
use App\Entity\GameCatalogue\GamePlatform;
use App\Entity\GameCatalogue\GameRelease;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class GameCatalogueControllerTest extends WebTestCase
{
    public function testPublicGameHubAndReleaseCalendarRenderPublishedCatalogue(): void
    {
        $client=static::createClient();
        $em=$this->em($client);
        $game=(new Game())->setName('Release Game')->setSlug('release-game')->setEnabled(true);
        $entry=(new GameCatalogueEntry($game))->setSummary('Catalogue summary')->setDeveloper('Studio');
        $platform=new GamePlatform('PC','pc');
        $edition=(new GameEdition($entry,'Deluxe'))->setSystemRequirements('16 GB RAM');
        $release=(new GameRelease($entry,$platform,'EU',new \DateTimeImmutable('+10 days')))->setEdition($edition);
        foreach([$game,$entry,$platform,$edition,$release] as $entity)$em->persist($entity);
        $em->flush();

        $client->request('GET','/games');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body','Release Game');

        $client->request('GET','/games/release-game');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body','Catalogue summary');
        self::assertSelectorTextContains('body','16 GB RAM');

        $client->request('GET','/games/releases');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body','Release Game');
        self::assertSelectorTextContains('body','PC');
    }

    public function testDisabledGamingModuleFailsClosed(): void
    {
        $client=static::createClient();
        $state=(new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0')->setEnabled(false);
        $this->em($client)->persist($state);
        $this->em($client)->flush();

        $client->request('GET','/games');
        self::assertResponseStatusCodeSame(404);
    }

    private function em(KernelBrowser $client):EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
