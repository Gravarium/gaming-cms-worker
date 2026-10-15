<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameGenre;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicGameCatalogueCsvControllerTest extends WebTestCase
{
    public function testCsvExportContainsOnlyPublicRowsAndUsesSafeAttachmentHeaders(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $suffix = bin2hex(random_bytes(4));
        $genre = new GameGenre('Strategy '.$suffix, 'strategy-'.$suffix);
        $entries = [];

        for ($index = 0; $index < 21; ++$index) {
            $name = $index === 0 ? '=SUM(1,2)' : 'Game '.$index.' '.$suffix;
            $game = (new Game())->setName($name)->setSlug('csv-game-'.$index.'-'.$suffix);
            $entry = (new GameCatalogueEntry($game))
                ->setSummary($index === 0 ? 'A summary, with "quotes"' : 'Summary '.$index)
                ->setDeveloper('Studio '.$suffix)
                ->addGenre($genre);
            $entries[] = [$game, $entry];
        }

        $hiddenGame = (new Game())->setName('Hidden game '.$suffix)->setSlug('csv-hidden-game-'.$suffix)->setEnabled(false);
        $hiddenEntry = (new GameCatalogueEntry($hiddenGame))->setSummary('Do not export');
        $disabledGame = (new Game())->setName('Disabled entry game '.$suffix)->setSlug('csv-disabled-entry-'.$suffix);
        $disabledEntry = (new GameCatalogueEntry($disabledGame))->setEnabled(false)->setSummary('Do not export');

        foreach ([$genre, $hiddenGame, $hiddenEntry, $disabledGame, $disabledEntry] as $entity) {
            $em->persist($entity);
        }
        foreach ($entries as [$game, $entry]) {
            $em->persist($game);
            $em->persist($entry);
        }
        $em->flush();

        $client->request('GET', '/games/catalogue.csv');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertResponseHeaderSame('Cache-Control', 'no-store');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString('attachment; filename=game-catalogue.csv', (string) $client->getResponse()->headers->get('Content-Disposition'));

        $csv = (string) $client->getResponse()->getContent();
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, null, ',', '"', '');
        self::assertSame(['Game', 'Summary', 'Developer', 'Genres'], $header);

        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        self::assertCount(21, $rows);
        self::assertSame("'=SUM(1,2)", $rows[0][0]);
        self::assertSame('A summary, with "quotes"', $rows[0][1]);
        self::assertSame('Strategy '.$suffix, $rows[0][3]);
        $exportedNames = array_column($rows, 0);
        self::assertNotContains('Hidden game '.$suffix, $exportedNames);
        self::assertNotContains('Disabled entry game '.$suffix, $exportedNames);
    }

    public function testDisabledGamingModuleHidesTheExport(): void
    {
        $client = static::createClient();
        $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0')->setEnabled(false);
        $this->em($client)->persist($state);
        $this->em($client)->flush();

        $client->request('GET', '/games/catalogue.csv');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Cache-Control', 'no-store');
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
