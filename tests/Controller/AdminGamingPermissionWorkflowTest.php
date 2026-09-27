<?php

declare(strict_types=1);

namespace App\\Tests\\Controller;

use App\\Entity\\Game;
use App\\Entity\\User;
use App\\Security\\CmsPermission;
use Doctrine\\ORM\\EntityManagerInterface;
use Symfony\\Bundle\\FrameworkBundle\\Test\\WebTestCase;

final class AdminGamingPermissionWorkflowTest extends WebTestCase
{
    public function testGamingManagerCanUseDashboardAndCreateGameWithoutAdminRole(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $token = bin2hex(random_bytes(6));
        $gameName = 'Permission workflow '.$token;
        $user = (new User())
            ->setEmail('gaming-workflow-'.$token.'@example.test')
            ->setDisplayName('Gaming workflow')
            ->setPermissions([CmsPermission::GAMING])
            ->setPassword('unused-test-hash');
        $userId = null;

        try {
            $entityManager->persist($user);
            $entityManager->flush();
            $userId = $user->getId();
            self::assertNotNull($userId);
            self::assertFalse($user->isAdmin());

            $client->loginUser($user);

            $client->request('GET', '/admin/gaming');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Spiele und Gilden');
            self::assertSelectorExists('a[href="/admin/gaming/game/new"]');

            $crawler = $client->request('GET', '/admin/gaming/game/new');
            self::assertResponseIsSuccessful();
            self::assertSame(1, $crawler->filter('input[name="game[_token]"]')->count());

            $form = $crawler->selectButton('Speichern')->form([
                'game[name]' => $gameName,
                'game[description]' => 'Synthetic permission workflow fixture',
                'game[enabled]' => '1',
            ]);
            $client->submit($form);

            self::assertResponseRedirects('/admin/gaming');

            $storedGame = $entityManager->getRepository(Game::class)->findOneBy(['name' => $gameName]);
            self::assertInstanceOf(Game::class, $storedGame);
            self::assertSame('permission-workflow-'.$token, $storedGame->getSlug());
            self::assertTrue($storedGame->isEnabled());
        } finally {
            $entityManager->clear();

            $storedGame = $entityManager->getRepository(Game::class)->findOneBy(['name' => $gameName]);
            if ($storedGame instanceof Game) {
                $entityManager->remove($storedGame);
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
