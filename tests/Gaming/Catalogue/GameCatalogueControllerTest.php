<?php

declare(strict_types=1);

namespace App\Tests\Gaming\Catalogue;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameEdition;
use App\Entity\GameCatalogue\GameGenre;
use App\Entity\GameCatalogue\GamePlatform;
use App\Entity\GameCatalogue\GameRelease;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameCatalogueControllerTest extends WebTestCase
{
    public function testPublicGameHubAndReleaseCalendarRenderPublishedCatalogue(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $game = (new Game())->setName('Release Game')->setSlug('release-game')->setEnabled(true);
        $entry = (new GameCatalogueEntry($game))->setSummary('Catalogue summary')->setDeveloper('Studio');
        $platform = new GamePlatform('PC', 'pc');
        $edition = (new GameEdition($entry, 'Deluxe'))->setSystemRequirements('16 GB RAM');
        $release = (new GameRelease($entry, $platform, 'EU', new \DateTimeImmutable('+10 days')))->setEdition($edition);
        foreach ([$game, $entry, $platform, $edition, $release] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->request('GET', '/games');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Release Game');

        $client->request('GET', '/games/release-game');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Catalogue summary');
        self::assertSelectorTextContains('body', '16 GB RAM');

        $client->request('GET', '/games/releases');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Release Game');
        self::assertSelectorTextContains('body', 'PC');
    }

    public function testPublicCatalogueFiltersCombineGenreAndPlatformAndOnlyExposeVisibleFacets(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $suffix = bin2hex(random_bytes(4));
        $releaseAt = new \DateTimeImmutable('+10 days');

        $rolePlaying = new GameGenre('Role-playing '.$suffix, 'rpg-'.$suffix);
        $racing = new GameGenre('Racing '.$suffix, 'racing-'.$suffix);
        $hiddenGenre = new GameGenre('Hidden genre '.$suffix, 'hidden-genre-'.$suffix);
        $pc = new GamePlatform('PC '.$suffix, 'pc-'.$suffix);
        $console = new GamePlatform('Console '.$suffix, 'console-'.$suffix);
        $hiddenPlatform = new GamePlatform('Hidden platform '.$suffix, 'hidden-platform-'.$suffix);
        $cancelledPlatform = new GamePlatform('Cancelled platform '.$suffix, 'cancelled-platform-'.$suffix);

        $gameA = (new Game())->setName('RPG Northwind '.$suffix)->setSlug('rpg-northwind-'.$suffix);
        $entryA = (new GameCatalogueEntry($gameA))->addGenre($rolePlaying);
        $releaseAPc = new GameRelease($entryA, $pc, 'EU', $releaseAt);
        $releaseACancelled = (new GameRelease($entryA, $cancelledPlatform, 'EU', $releaseAt))->setStatus('cancelled');

        $gameB = (new Game())->setName('RPG Pixel '.$suffix)->setSlug('rpg-pixel-'.$suffix);
        $entryB = (new GameCatalogueEntry($gameB))->addGenre($rolePlaying);
        $releaseBConsole = new GameRelease($entryB, $console, 'EU', $releaseAt);

        $gameC = (new Game())->setName('Racer '.$suffix)->setSlug('racer-'.$suffix);
        $entryC = (new GameCatalogueEntry($gameC))->addGenre($racing);
        $releaseCPc = new GameRelease($entryC, $pc, 'EU', $releaseAt);

        $hiddenGame = (new Game())
            ->setName('Hidden Game '.$suffix)
            ->setSlug('hidden-game-'.$suffix)
            ->setEnabled(false);
        $hiddenEntry = (new GameCatalogueEntry($hiddenGame))->addGenre($hiddenGenre);
        $hiddenRelease = new GameRelease($hiddenEntry, $hiddenPlatform, 'EU', $releaseAt);

        foreach ([
            $rolePlaying, $racing, $hiddenGenre, $pc, $console, $hiddenPlatform, $cancelledPlatform,
            $gameA, $entryA, $releaseAPc, $releaseACancelled,
            $gameB, $entryB, $releaseBConsole,
            $gameC, $entryC, $releaseCPc,
            $hiddenGame, $hiddenEntry, $hiddenRelease,
        ] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $crawler = $client->request('GET', '/games');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('form[method="get"][action="/games"]')->count());
        self::assertSame(1, $crawler->filter('label[for="game-catalogue-genre"]')->count());
        self::assertSame(1, $crawler->filter('label[for="game-catalogue-platform"]')->count());
        self::assertSame(1, $crawler->filter('#game-catalogue-genre option[value="'.$rolePlaying->getSlug().'"]')->count());
        self::assertSame(0, $crawler->filter('#game-catalogue-genre option[value="'.$hiddenGenre->getSlug().'"]')->count());
        self::assertSame(0, $crawler->filter('#game-catalogue-platform option[value="'.$hiddenPlatform->getSlug().'"]')->count());
        self::assertSame(0, $crawler->filter('#game-catalogue-platform option[value="'.$cancelledPlatform->getSlug().'"]')->count());
        self::assertSame(1, $crawler->filter('a[href="/games"]')->count());

        $client->request('GET', '/games', ['genre' => $rolePlaying->getSlug()]);
        self::assertResponseIsSuccessful();
        $genreBody = $client->getResponse()->getContent();
        self::assertIsString($genreBody);
        self::assertStringContainsString($gameA->getName(), $genreBody);
        self::assertStringContainsString($gameB->getName(), $genreBody);
        self::assertStringNotContainsString($gameC->getName(), $genreBody);
        self::assertStringNotContainsString($hiddenGame->getName(), $genreBody);

        $client->request('GET', '/games', ['platform' => $pc->getSlug()]);
        self::assertResponseIsSuccessful();
        $platformBody = $client->getResponse()->getContent();
        self::assertIsString($platformBody);
        self::assertStringContainsString($gameA->getName(), $platformBody);
        self::assertStringContainsString($gameC->getName(), $platformBody);
        self::assertStringNotContainsString($gameB->getName(), $platformBody);

        $client->request('GET', '/games', ['genre' => $rolePlaying->getSlug(), 'platform' => $pc->getSlug()]);
        self::assertResponseIsSuccessful();
        $combinedBody = $client->getResponse()->getContent();
        self::assertIsString($combinedBody);
        self::assertStringContainsString($gameA->getName(), $combinedBody);
        self::assertStringNotContainsString($gameB->getName(), $combinedBody);
        self::assertStringNotContainsString($gameC->getName(), $combinedBody);

        $client->request('GET', '/games', ['genre' => '', 'platform' => '']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('[role="status"]', 'nicht verfügbar');
        self::assertStringContainsString($gameA->getName(), (string) $client->getResponse()->getContent());
    }

    public function testMalformedUnknownAndUnavailableFiltersFailClosedWithoutEchoingInput(): void
    {
        $client = static::createClient();
        $unknown = 'unavailable-'.bin2hex(random_bytes(4));
        $overlong = str_repeat('x', 121);

        foreach ([
            ['genre' => ['malformed-array']],
            ['genre' => $unknown],
            ['genre' => $overlong],
        ] as $parameters) {
            $client->request('GET', '/games', $parameters);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('[role="status"]', 'nicht verfügbar');
            $body = $client->getResponse()->getContent();
            self::assertIsString($body);
            self::assertStringNotContainsString($unknown, $body);
            self::assertStringNotContainsString($overlong, $body);
            self::assertStringNotContainsString('malformed-array', $body);
        }
    }

    public function testDisabledGamingModuleFailsClosed(): void
    {
        $client = static::createClient();
        $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0')->setEnabled(false);
        $this->em($client)->persist($state);
        $this->em($client)->flush();

        $client->request('GET', '/games');
        self::assertResponseStatusCodeSame(404);
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
