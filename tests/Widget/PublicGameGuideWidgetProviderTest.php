<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\User;
use App\GameGuide\PublicGameGuideQuery;
use App\Security\CmsPermission;
use App\Widget\WidgetDefinition;
use App\Widget\WidgetRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class PublicGameGuideWidgetProviderTest extends WebTestCase
{
    private const KEY = 'gaming.public-guides';

    public function testWidgetIsDiscoverableAndBoundsPublicEnabledGuides(): void
    {
        $client = static::createClient();
        $this->setModules($client, true);
        $em = $this->em($client);
        $connection = $this->connection($client);
        $game = $this->game($em, true);
        $disabledGame = $this->game($em, false);
        $author = $this->user($em);
        $gameId = $game->getId();
        $disabledGameId = $disabledGame->getId();
        $authorId = $author->getId();
        self::assertNotNull($gameId);
        self::assertNotNull($disabledGameId);
        self::assertNotNull($authorId);
        $guideIds = [];

        try {
            for ($index = 0; $index < 15; ++$index) {
                $guideIds[] = $this->guide(
                    $connection,
                    $gameId,
                    $authorId,
                    sprintf('WCP552-WIDGET-%02d', $index),
                    (new \DateTimeImmutable('-'.$index.' seconds'))->format('Y-m-d H:i:s'),
                );
            }
            $hiddenId = $this->guide(
                $connection,
                $disabledGameId,
                $authorId,
                'WCP552-WIDGET-DISABLED',
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            );
            $guideIds[] = $hiddenId;

            $registry = $client->getContainer()->get(WidgetRegistry::class);
            $definition = $registry->get(self::KEY);
            self::assertInstanceOf(WidgetDefinition::class, $definition);
            self::assertSame('gaming', $definition->module);
            self::assertFalse($definition->multiple);
            self::assertTrue($registry->available(self::KEY));
            self::assertContains(
                self::KEY,
                array_map(static fn (WidgetDefinition $item): string => $item->key, $registry->availableDefinitions()),
            );

            $data = $registry->data(self::KEY, ['count' => 99]);
            self::assertSame(['guides'], array_keys($data));
            self::assertIsArray($data['guides']);
            self::assertCount(12, $data['guides']);
            $titles = array_column($data['guides'], 'title');
            self::assertContains('WCP552-WIDGET-00', $titles);
            self::assertNotContains('WCP552-WIDGET-DISABLED', $titles);
            $secondData = $registry->data(self::KEY, ['count' => 99]);
            self::assertIsArray($secondData['guides']);
            self::assertSame($titles, array_column($secondData['guides'], 'title'));
            self::assertCount(3, $registry->data(self::KEY, ['count' => 3])['guides']);

            $html = $client->getContainer()->get(Environment::class)->render($definition->template, $registry->data(self::KEY, ['count' => 3]));
            self::assertStringContainsString('WCP552-WIDGET-00', $html);
            self::assertStringContainsString('/gaming/guides/', $html);
            self::assertStringNotContainsString('author_id', $html);
            self::assertStringNotContainsString($author->getEmail(), $html);

            $emptyHtml = $client->getContainer()->get(Environment::class)->render($definition->template, ['guides' => []]);
            self::assertStringContainsString('Zurzeit gibt es keine öffentlichen Gaming-Guides.', $emptyHtml);
        } finally {
            $this->cleanup($client, $guideIds, [$authorId], [$gameId, $disabledGameId]);
        }
    }

    public function testDisabledGamingHidesWidgetAndItsData(): void
    {
        $client = static::createClient();
        $this->setModules($client, false);

        try {
            $registry = $client->getContainer()->get(WidgetRegistry::class);
            self::assertFalse($registry->available(self::KEY));
            self::assertSame([], $registry->data(self::KEY, ['count' => 6]));
            self::assertNotContains(
                self::KEY,
                array_map(static fn (WidgetDefinition $item): string => $item->key, $registry->availableDefinitions()),
            );
            self::assertSame([], $client->getContainer()->get(PublicGameGuideQuery::class)->latest());
        } finally {
            $this->setModules($client, null);
        }
    }

    private function game(EntityManagerInterface $em, bool $enabled): Game
    {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())
            ->setName('Widget guide game '.$suffix)
            ->setSlug('widget-guide-game-'.$suffix)
            ->setEnabled($enabled);
        $em->persist($game);
        $em->flush();

        return $game;
    }

    private function user(EntityManagerInterface $em): User
    {
        $user = (new User())
            ->setEmail('widget-guide-'.bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName('Widget guide author')
            ->setPermissions([CmsPermission::GAMING])
            ->verifyEmail();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function guide(Connection $connection, int $gameId, int $authorId, string $title, string $publishedAt): int
    {
        return (int) $connection->fetchOne(
            "INSERT INTO game_guide (game_id, author_id, reviewer_id, title, guide_type, game_version, season, valid_from, valid_until, review_status, published_at)
             VALUES (:game, :author, NULL, :title, 'build', '2.4', 'Test', :from, NULL, 'published', :published) RETURNING id",
            [
                'game' => $gameId,
                'author' => $authorId,
                'title' => $title,
                'from' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
                'published' => $publishedAt,
            ],
        );
    }

    /** @param list<int> $guideIds @param list<int> $userIds @param list<int> $gameIds */
    private function cleanup(KernelBrowser $client, array $guideIds, array $userIds, array $gameIds): void
    {
        $connection = $this->connection($client);
        foreach ($guideIds as $id) {
            $connection->executeStatement('DELETE FROM game_guide WHERE id = :id', ['id' => $id]);
        }
        $em = $this->em($client);
        $em->clear();
        foreach ($userIds as $id) {
            $user = $em->find(User::class, $id);
            if ($user instanceof User) {
                $em->remove($user);
            }
        }
        foreach ($gameIds as $id) {
            $game = $em->find(Game::class, $id);
            if ($game instanceof Game) {
                $em->remove($game);
            }
        }
        $em->flush();
        $this->setModules($client, null);
    }

    private function setModules(KernelBrowser $client, ?bool $enabled): void
    {
        $em = $this->em($client);
        foreach (['content', 'gaming'] as $key) {
            $state = $em->find(CmsModuleState::class, $key);
            if ($enabled === null) {
                if ($state instanceof CmsModuleState) {
                    $em->remove($state);
                }
                continue;
            }
            if (!$state instanceof CmsModuleState) {
                $state = (new CmsModuleState())->setModuleKey($key)->updateVersion('1.0.0');
            }
            $state->setEnabled($enabled);
            $em->persist($state);
        }
        $em->flush();
        $em->clear();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }

    private function connection(KernelBrowser $client): Connection
    {
        return $client->getContainer()->get(Connection::class);
    }
}
