<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminGamingGameDeletionSecurityTest extends WebTestCase
{
    public function testGameDeletionRequiresItsRenderedCsrfToken(): void
    {
        $client = static::createClient();
        $token = bin2hex(random_bytes(6));
        $game = (new Game())
            ->setName('Delete boundary '.$token)
            ->setSlug('delete-boundary-'.$token);
        $user = (new User())
            ->setEmail('game-delete-'.$token.'@example.test')
            ->setDisplayName('Game delete manager')
            ->setPermissions([CmsPermission::GAMING])
            ->setPassword('unused-test-hash');
        $gameId = null;
        $userId = null;

        try {
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            $entityManager->persist($game);
            $entityManager->persist($user);
            $entityManager->flush();
            $gameId = $game->getId();
            $userId = $user->getId();
            self::assertNotNull($gameId);
            self::assertNotNull($userId);
            self::assertFalse($user->isAdmin());

            $client->loginUser($user);
            $deletePath = '/admin/gaming/game/'.$gameId.'/delete';

            $client->request('POST', $deletePath);
            self::assertResponseStatusCodeSame(403);

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            $entityManager->clear();
            $storedGame = $entityManager->find(Game::class, $gameId);
            self::assertInstanceOf(Game::class, $storedGame);

            $crawler = $client->request('GET', '/admin/gaming');
            self::assertResponseIsSuccessful();
            $tokenField = $crawler->filter('form[action="'.$deletePath.'"] input[name="_token"]');
            self::assertSame(1, $tokenField->count());
            $csrfToken = (string) $tokenField->attr('value');
            self::assertNotSame('', $csrfToken);

            $client->request('POST', $deletePath, ['_token' => $csrfToken]);
            self::assertResponseRedirects('/admin/gaming');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.notice', 'Das Spiel wurde gelöscht.');

            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            $entityManager->clear();
            self::assertNull($entityManager->find(Game::class, $gameId));
        } finally {
            $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
            $entityManager->clear();

            if ($gameId !== null) {
                $storedGame = $entityManager->find(Game::class, $gameId);
                if ($storedGame instanceof Game) {
                    $entityManager->remove($storedGame);
                }
            }

            if ($userId !== null) {
                $storedUser = $entityManager->find(User::class, $userId);
                if ($storedUser instanceof User) {
                    $entityManager->remove($storedUser);
                }
            }

            $entityManager->flush();
        }
    }
}
