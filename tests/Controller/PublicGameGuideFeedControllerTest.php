<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\User;
use App\GameGuide\PublicGameGuideQuery;
use App\Security\CmsPermission;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicGameGuideFeedControllerTest extends WebTestCase
{
    public function testFeedIsValidBoundedXmlAndContainsOnlyPublishedGuides(): void
    {
        $client = static::createClient();
        $this->setModules($client, true);
        $em = $this->em($client);
        $connection = $this->connection($client);
        $guideIds = [];
        $userIds = [];
        $gameIds = [];

        try {
            $game = $this->game($em, true);
            $gameId = $game->getId();
            self::assertNotNull($gameId);
            $gameIds[] = $gameId;

            $otherGame = $this->game($em, true);
            $otherGameId = $otherGame->getId();
            self::assertNotNull($otherGameId);
            $gameIds[] = $otherGameId;

            $disabledGame = $this->game($em, false);
            $disabledGameId = $disabledGame->getId();
            self::assertNotNull($disabledGameId);
            $gameIds[] = $disabledGameId;

            $author = $this->user($client);
            $authorId = $author->getId();
            self::assertNotNull($authorId);
            $userIds[] = $authorId;

            $now = new \DateTimeImmutable();
            $publishedTitles = [];
            for ($index = 0; $index < 13; ++$index) {
                $title = $index === 0 ? 'WCP613 RSS & <Guide> "latest"' : 'WCP613 RSS guide '.$index;
                $targetGameId = $index < 10 ? $gameId : $otherGameId;
                $guideIds[] = $this->guide(
                    $connection,
                    $targetGameId,
                    $authorId,
                    'published',
                    $title,
                    $now->modify('-'.($index + 1).' minutes')->format('Y-m-d H:i:s'),
                );
                $publishedTitles[] = $title;
            }

            $disabledGuideId = $this->guide(
                $connection,
                $disabledGameId,
                $authorId,
                'published',
                'WCP613 hidden disabled game',
            );
            $guideIds[] = $disabledGuideId;

            foreach (['draft', 'review', 'scheduled'] as $status) {
                $guideIds[] = $this->guide(
                    $connection,
                    $gameId,
                    $authorId,
                    $status,
                    'WCP613 hidden '.$status,
                );
            }

            $futureId = $this->guide(
                $connection,
                $gameId,
                $authorId,
                'published',
                'WCP613 hidden future',
                $now->modify('+1 day')->format('Y-m-d H:i:s'),
            );
            $guideIds[] = $futureId;

            $client->request('GET', '/gaming/guides');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('href="/gaming/guides.xml"', (string) $client->getResponse()->getContent());

            $client->request('GET', '/gaming/guides.xml');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('application/rss+xml', (string) $client->getResponse()->headers->get('Content-Type'));
            self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
            $body = $client->getResponse()->getContent();
            self::assertIsString($body);

            $xml = new \DOMDocument();
            self::assertTrue($xml->loadXML($body));
            $items = $xml->getElementsByTagName('item');
            self::assertSame(PublicGameGuideQuery::MAX_RESULTS, $items->length);

            $titles = [];
            $links = [];
            foreach ($items as $item) {
                if (!$item instanceof \DOMElement) {
                    continue;
                }
                $title = $item->getElementsByTagName('title')->item(0);
                $link = $item->getElementsByTagName('link')->item(0);
                if ($title instanceof \DOMElement && $link instanceof \DOMElement) {
                    $titles[] = $title->textContent;
                    $links[$title->textContent] = $link->textContent;
                }
            }

            self::assertSame($publishedTitles[0], $titles[0]);
            self::assertContains($publishedTitles[10], $titles);
            self::assertContains($publishedTitles[11], $titles);
            self::assertNotContains($publishedTitles[12], $titles);
            self::assertNotContains('WCP613 hidden disabled game', $titles);
            self::assertNotContains('WCP613 hidden draft', $titles);
            self::assertNotContains('WCP613 hidden review', $titles);
            self::assertNotContains('WCP613 hidden scheduled', $titles);
            self::assertNotContains('WCP613 hidden future', $titles);

            self::assertArrayHasKey($publishedTitles[0], $links);
            self::assertStringStartsWith('http://', $links[$publishedTitles[0]]);
            self::assertStringEndsWith('/gaming/guides/'.$guideIds[0], $links[$publishedTitles[0]]);
            self::assertStringContainsString('&amp; &lt;Guide&gt;', $body);
            self::assertStringNotContainsString($author->getEmail(), $body);

            $this->setModules($client, false);
            $client->request('GET', '/gaming/guides.xml');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, $guideIds, $userIds, $gameIds);
        }
    }

    private function game(EntityManagerInterface $em, bool $enabled): Game
    {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())
            ->setName('Public guide feed game '.$suffix)
            ->setSlug('public-guide-feed-game-'.$suffix)
            ->setEnabled($enabled);
        $em->persist($game);
        $em->flush();

        return $game;
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('public-guide-feed-'.bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName('Public guide feed author')
            ->setPermissions([CmsPermission::GAMING])
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function guide(
        Connection $connection,
        int $gameId,
        ?int $authorId,
        string $status,
        string $title,
        ?string $publishedAt = null,
    ): int {
        if ($status === 'published') {
            $publishedAt ??= (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');
        } else {
            $publishedAt = null;
        }

        return (int) $connection->fetchOne(
            'INSERT INTO game_guide (game_id, author_id, reviewer_id, title, guide_type, game_version, season, valid_from, valid_until, review_status, published_at)
             VALUES (:game, :author, NULL, :title, :type, :version, :season, :from, NULL, :status, :published) RETURNING id',
            [
                'game' => $gameId,
                'author' => $authorId,
                'title' => $title,
                'type' => 'build',
                'version' => '2.4',
                'season' => 'Test',
                'from' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
                'status' => $status,
                'published' => $publishedAt,
            ],
        );
    }

    /**
     * @param list<int> $guideIds
     * @param list<int> $userIds
     * @param list<int> $gameIds
     */
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
