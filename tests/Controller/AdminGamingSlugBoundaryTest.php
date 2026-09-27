<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\String\Slugger\SluggerInterface;

final class AdminGamingSlugBoundaryTest extends WebTestCase
{
    public function testLongUnicodeGameAndGuildSlugsStayWithinTheirColumnLimit(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(6));
        $name = str_repeat('æœßĳ', 20).' '.$suffix;
        $rawSlug = $client->getContainer()->get(SluggerInterface::class)->slug($name)->toString();

        self::assertLessThanOrEqual(120, mb_strlen($name));
        self::assertGreaterThan(140, mb_strlen($rawSlug));

        $user = (new User())
            ->setEmail('gaming-slug-'.$suffix.'@example.test')
            ->setDisplayName('Gaming slug boundary')
            ->setPermissions([CmsPermission::GAMING])
            ->setPassword('unused-test-hash');
        $userId = null;

        try {
            $entityManager->persist($user);
            $entityManager->flush();
            $userId = $user->getId();
            self::assertNotNull($userId);
            $client->loginUser($user);

            $this->submitGame($client, $name);
            $this->submitGame($client, $name);

            $games = $entityManager->getRepository(Game::class)->findBy(['name' => $name], ['id' => 'ASC']);
            self::assertCount(2, $games);
            self::assertInstanceOf(Game::class, $games[0]);
            self::assertInstanceOf(Game::class, $games[1]);
            self::assertLessThanOrEqual(140, mb_strlen($games[0]->getSlug()));
            self::assertLessThanOrEqual(140, mb_strlen($games[1]->getSlug()));
            self::assertNotSame($games[0]->getSlug(), $games[1]->getSlug());
            self::assertStringEndsWith('-2', $games[1]->getSlug());

            $gameId = $games[0]->getId();
            self::assertNotNull($gameId);
            $this->submitGuild($client, $gameId, $name);
            $this->submitGuild($client, $gameId, $name);

            $guilds = $entityManager->getRepository(Guild::class)->findBy(['name' => $name], ['id' => 'ASC']);
            self::assertCount(2, $guilds);
            self::assertInstanceOf(Guild::class, $guilds[0]);
            self::assertInstanceOf(Guild::class, $guilds[1]);
            self::assertLessThanOrEqual(140, mb_strlen($guilds[0]->getSlug()));
            self::assertLessThanOrEqual(140, mb_strlen($guilds[1]->getSlug()));
            self::assertNotSame($guilds[0]->getSlug(), $guilds[1]->getSlug());
            self::assertStringEndsWith('-2', $guilds[1]->getSlug());
        } finally {
            $entityManager->clear();

            foreach ($entityManager->getRepository(Guild::class)->findBy(['name' => $name]) as $guild) {
                $entityManager->remove($guild);
            }
            $entityManager->flush();

            foreach ($entityManager->getRepository(Game::class)->findBy(['name' => $name]) as $game) {
                $entityManager->remove($game);
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

    private function submitGame(KernelBrowser $client, string $name): void
    {
        $crawler = $client->request('GET', '/admin/gaming/game/new');
        $form = $crawler->selectButton('Speichern')->form([
            'game[name]' => $name,
            'game[description]' => 'Synthetic slug boundary fixture',
            'game[enabled]' => '1',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/gaming');
    }

    private function submitGuild(KernelBrowser $client, int $gameId, string $name): void
    {
        $crawler = $client->request('GET', '/admin/gaming/guild/new');
        $form = $crawler->selectButton('Speichern')->form([
            'guild[game]' => (string) $gameId,
            'guild[name]' => $name,
            'guild[serverName]' => 'Synthetic test server',
            'guild[description]' => 'Synthetic slug boundary fixture',
            'guild[enabled]' => '1',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/gaming');
    }
}
