<?php

declare(strict_types=1);

namespace App\Tests\Gaming\Catalogue;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\GameCatalogue\GameCatalogueEntry;
use App\Entity\GameCatalogue\GameGenre;
use App\Entity\GameCatalogue\GamePublisher;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminGameCatalogueControllerTest extends WebTestCase
{
    public function testAdministrationRequiresGamingPermissionAndIsLinkedFromGamingDashboard(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/gaming/catalogue');
        self::assertResponseRedirects('/login');

        $client = static::createClient();
        $client->loginUser($this->user($client, [CmsPermission::ACCESS]));
        $client->request('GET', '/admin/gaming/catalogue');
        self::assertResponseStatusCodeSame(403);

        $client = static::createClient();
        $client->loginUser($this->user($client, [CmsPermission::ACCESS, CmsPermission::GAMING]));
        $client->request('GET', '/admin/gaming');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/gaming/catalogue"]');

        $client->request('GET', '/admin/gaming/catalogue');
        self::assertResponseIsSuccessful();
    }

    public function testManagerCanCreateAndEditAGameHubAndControlItsPublicVisibility(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, [CmsPermission::GAMING]));
        $em = $this->em($client);
        $suffix = bin2hex(random_bytes(4));
        $game = (new Game())
            ->setName('Managed Game '.$suffix)
            ->setSlug('managed-game-'.$suffix)
            ->setEnabled(true);
        $publisher = new GamePublisher('Managed Publisher '.$suffix, 'managed-publisher-'.$suffix);
        $genre = new GameGenre('Managed Genre '.$suffix, 'managed-genre-'.$suffix);
        foreach ([$game, $publisher, $genre] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $gameId = $game->getId();
        $publisherId = $publisher->getId();
        $genreId = $genre->getId();
        self::assertNotNull($gameId);
        self::assertNotNull($publisherId);
        self::assertNotNull($genreId);

        $crawler = $client->request('GET', '/admin/gaming/catalogue/new?game='.$gameId);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('form input[name$="[_token]"]')->count());
        $formName = (string) $crawler->filter('form')->attr('name');
        $token = (string) $crawler->filter('form input[name$="[_token]"]')->attr('value');
        $client->request('POST', '/admin/gaming/catalogue/new?game='.$gameId, [
            $formName => [
                '_token' => $token,
                'gameChoice' => (string) $gameId,
                'publisher' => (string) $publisherId,
                'developer' => 'Northwind Studio',
                'summary' => 'An adventure managed through the CMS.',
                'enabled' => '1',
                'genres' => [(string) $genreId],
            ],
        ]);
        self::assertResponseRedirects('/admin/gaming/catalogue');

        $em = $this->em($client);
        $em->clear();
        $entry = $em->getRepository(GameCatalogueEntry::class)->findOneBy(['game' => $game]);
        self::assertInstanceOf(GameCatalogueEntry::class, $entry);
        self::assertSame('Northwind Studio', $entry->getDeveloper());
        self::assertSame('An adventure managed through the CMS.', $entry->getSummary());
        self::assertSame('Managed Publisher '.$suffix, $entry->getPublisher()?->getName());
        self::assertCount(1, $entry->getGenres());
        $savedGenre = $entry->getGenres()->first();
        self::assertInstanceOf(GameGenre::class, $savedGenre);
        self::assertSame('Managed Genre '.$suffix, $savedGenre->getName());
        $entryId = $entry->getId();
        self::assertNotNull($entryId);

        $client->request('GET', '/games');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Managed Game '.$suffix);

        $client->request('GET', '/games/managed-game-'.$suffix);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Northwind Studio');
        self::assertSelectorTextContains('body', 'An adventure managed through the CMS.');
        self::assertSelectorTextContains('body', 'Managed Genre '.$suffix);

        $crawler = $client->request('GET', '/admin/gaming/catalogue/'.$entryId.'/edit');
        self::assertResponseIsSuccessful();
        $formName = (string) $crawler->filter('form')->attr('name');
        $token = (string) $crawler->filter('form input[name$="[_token]"]')->attr('value');
        $client->request('POST', '/admin/gaming/catalogue/'.$entryId.'/edit', [
            $formName => [
                '_token' => $token,
                'publisher' => (string) $publisherId,
                'developer' => 'Updated Studio',
                'summary' => 'Updated hub description.',
                'genres' => [],
            ],
        ]);
        self::assertResponseRedirects('/admin/gaming/catalogue');

        $em = $this->em($client);
        $em->clear();
        $saved = $em->find(GameCatalogueEntry::class, $entryId);
        self::assertInstanceOf(GameCatalogueEntry::class, $saved);
        self::assertFalse($saved->isEnabled(), 'Omitting the checkbox when editing must hide the hub.');
        self::assertSame('Updated Studio', $saved->getDeveloper());
        self::assertSame('Updated hub description.', $saved->getSummary());
        self::assertCount(0, $saved->getGenres());

        $client->request('GET', '/games');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Managed Game '.$suffix, (string) $client->getResponse()->getContent());
        $client->request('GET', '/games/managed-game-'.$suffix);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/admin/gaming/catalogue');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Im Katalog ausgeblendet');
    }

    public function testDuplicateGameChoicesAndInvalidCsrfCannotCreateAnotherHub(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, [CmsPermission::GAMING]));
        $em = $this->em($client);
        $existingGame = $this->persistGame($client, 'Existing');
        $availableGame = $this->persistGame($client, 'Available');
        $existingGameId = $existingGame->getId();
        $availableGameId = $availableGame->getId();
        self::assertNotNull($existingGameId);
        self::assertNotNull($availableGameId);
        $existingEntry = new GameCatalogueEntry($existingGame);
        $em->persist($existingEntry);
        $em->flush();

        $crawler = $client->request('GET', '/admin/gaming/catalogue/new?game='.$existingGameId);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('select[name$="[gameChoice]"] option[value="'.$existingGameId.'"]')->count());
        $formName = (string) $crawler->filter('form')->attr('name');
        $token = (string) $crawler->filter('form input[name$="[_token]"]')->attr('value');
        $client->request('POST', '/admin/gaming/catalogue/new', [
            $formName => [
                '_token' => $token,
                'gameChoice' => (string) $existingGameId,
                'developer' => 'Duplicate attempt',
                'summary' => 'This must not be stored.',
                'enabled' => '1',
                'genres' => [],
            ],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'bereits einen Game Hub');
        $em = $this->em($client);
        self::assertSame(1, $em->getRepository(GameCatalogueEntry::class)->count(['game' => $existingGame]));

        $crawler = $client->request('GET', '/admin/gaming/catalogue/new?game='.$availableGameId);
        $formName = (string) $crawler->filter('form')->attr('name');
        $client->request('POST', '/admin/gaming/catalogue/new', [
            $formName => [
                '_token' => 'invalid-token',
                'gameChoice' => (string) $availableGameId,
                'developer' => 'CSRF attempt',
                'summary' => 'This must not be stored either.',
                'enabled' => '1',
                'genres' => [],
            ],
        ]);
        self::assertResponseIsSuccessful();
        $em = $this->em($client);
        self::assertSame(0, $em->getRepository(GameCatalogueEntry::class)->count(['game' => $availableGame]));
    }

    public function testSummaryLengthValidationExplainsWhyTheGameHubWasNotSaved(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, [CmsPermission::GAMING]));
        $em = $this->em($client);
        $game = $this->persistGame($client, 'LongSummary');
        $gameId = $game->getId();
        self::assertNotNull($gameId);
        $crawler = $client->request('GET', '/admin/gaming/catalogue/new?game='.$gameId);
        $formName = (string) $crawler->filter('form')->attr('name');
        $token = (string) $crawler->filter('form input[name$="[_token]"]')->attr('value');

        $client->request('POST', '/admin/gaming/catalogue/new', [
            $formName => [
                '_token' => $token,
                'gameChoice' => (string) $gameId,
                'developer' => 'Studio',
                'summary' => str_repeat('x', 5001),
                'enabled' => '1',
                'genres' => [],
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'höchstens 5000 Zeichen');
        $em = $this->em($client);
        self::assertSame(0, $em->getRepository(GameCatalogueEntry::class)->count(['game' => $game]));
    }

    public function testDisabledGamingModuleKeepsAdminStateVisibleAndHidesThePublicHub(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, [CmsPermission::GAMING]));
        $em = $this->em($client);
        $game = $this->persistGame($client, 'ModuleOff');
        $entry = new GameCatalogueEntry($game);
        $em->persist($entry);
        $em->flush();
        $gameId = $game->getId();
        self::assertNotNull($gameId);

        $game->setEnabled(false);
        $em->flush();

        $client->request('GET', '/admin/gaming/catalogue');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Spiel ausgeblendet');
        $client->request('GET', '/games/'.$game->getSlug());
        self::assertResponseStatusCodeSame(404);

        $em = $this->em($client);
        $managedGame = $em->find(Game::class, $gameId);
        self::assertInstanceOf(Game::class, $managedGame);
        $managedGame->setEnabled(true);
        $em->flush();

        $state = $em->getRepository(CmsModuleState::class)->find('gaming');
        $stateWasPresent = $state instanceof CmsModuleState;
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('gaming')->updateVersion('1.0.0');
            $em->persist($state);
        }
        $previousState = $state->isEnabled();
        $state->setEnabled(false);
        $em->flush();

        try {
            $client->request('GET', '/admin/gaming/catalogue');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Gaming-Modul deaktiviert');
            self::assertSelectorTextContains('body', 'Game Hubs verwalten');

            $client->request('GET', '/games');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/games/'.$game->getSlug());
            self::assertResponseStatusCodeSame(404);
        } finally {
            $cleanupEntityManager = $this->em($client);
            $currentState = $cleanupEntityManager->getRepository(CmsModuleState::class)->find('gaming');
            if ($stateWasPresent && $currentState instanceof CmsModuleState) {
                $currentState->setEnabled($previousState);
            } elseif ($currentState instanceof CmsModuleState) {
                $cleanupEntityManager->remove($currentState);
            }
            $cleanupEntityManager->flush();
        }
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('game-hub-admin-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Game Hub admin test')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function persistGame(KernelBrowser $client, string $prefix): Game
    {
        $suffix = bin2hex(random_bytes(4));
        $game = (new Game())
            ->setName($prefix.' game '.$suffix)
            ->setSlug(strtolower($prefix).'-game-'.$suffix)
            ->setEnabled(true);
        $this->em($client)->persist($game);
        $this->em($client)->flush();

        return $game;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
