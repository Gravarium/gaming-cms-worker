<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicGameGuideControllerTest extends WebTestCase
{
    public function testPublicPagesHideUnpublishedDisabledAndFutureGuides(): void
    {
        $client = static::createClient();
        $this->setModules($client, true);
        $em = $this->em($client);
        $connection = $this->connection($client);
        $enabledGame = $this->game($em, true);
        $disabledGame = $this->game($em, false);
        $author = $this->user($em);
        $reviewer = $this->user($em);
        $enabledGameId = $enabledGame->getId();
        $disabledGameId = $disabledGame->getId();
        $authorId = $author->getId();
        $reviewerId = $reviewer->getId();
        self::assertNotNull($enabledGameId);
        self::assertNotNull($disabledGameId);
        self::assertNotNull($authorId);
        self::assertNotNull($reviewerId);
        $guideIds = [];

        try {
            $publicId = $this->guide($connection, $enabledGameId, $authorId, $reviewerId, 'published', 'WCP552-Visible <img src=x onerror=alert(1)>');
            $guideIds[] = $publicId;
            $connection->executeStatement(
                'INSERT INTO game_guide_component (guide_id, component_type, component_key, position, alternatives)
                 VALUES (:guide, :type, :key, :position, CAST(:alternatives AS JSON))',
                ['guide' => $publicId, 'type' => 'gear', 'key' => 'ember-staff', 'position' => 1, 'alternatives' => '["oak-wand"]'],
            );
            $draftId = $this->guide($connection, $enabledGameId, $authorId, null, 'draft', 'WCP552-HiddenDraft');
            $guideIds[] = $draftId;
            $reviewId = $this->guide($connection, $enabledGameId, $authorId, null, 'review', 'WCP552-HiddenReview');
            $guideIds[] = $reviewId;
            $futureId = $this->guide(
                $connection,
                $enabledGameId,
                $authorId,
                $reviewerId,
                'published',
                'WCP552-HiddenFuture',
                (new \DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s'),
            );
            $guideIds[] = $futureId;
            $disabledId = $this->guide($connection, $disabledGameId, $authorId, $reviewerId, 'published', 'WCP552-HiddenDisabledGame');
            $guideIds[] = $disabledId;

            $client->request('GET', '/gaming/guides');
            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('WCP552-Visible', $html);
            self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
            self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
            self::assertStringNotContainsString('WCP552-HiddenDraft', $html);
            self::assertStringNotContainsString('WCP552-HiddenReview', $html);
            self::assertStringNotContainsString('WCP552-HiddenFuture', $html);
            self::assertStringNotContainsString('WCP552-HiddenDisabledGame', $html);
            self::assertStringNotContainsString($author->getEmail(), $html);
            self::assertStringNotContainsString($reviewer->getEmail(), $html);
            self::assertStringNotContainsString('author_id', $html);
            self::assertStringNotContainsString('reviewer_id', $html);

            $client->request('GET', '/gaming/guides/'.$publicId);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'WCP552-Visible');
            self::assertSelectorTextContains('main', 'ember-staff');
            self::assertStringNotContainsString($author->getEmail(), (string) $client->getResponse()->getContent());

            foreach ([$draftId, $reviewId, $futureId, $disabledId] as $hiddenId) {
                $client->request('GET', '/gaming/guides/'.$hiddenId);
                self::assertResponseStatusCodeSame(404);
            }

            $client->request('GET', '/gaming/guides?game='.$disabledGame->getSlug());
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, $guideIds, [$authorId, $reviewerId], [$enabledGameId, $disabledGameId]);
        }
    }

    public function testPublicSearchAndPaginationAreStableAndPreserveFilters(): void
    {
        $client = static::createClient();
        $this->setModules($client, true);
        $em = $this->em($client);
        $connection = $this->connection($client);
        $game = $this->game($em, true);
        $gameId = $game->getId();
        self::assertNotNull($gameId);
        $guideIds = [];

        try {
            $now = new \DateTimeImmutable();
            for ($index = 0; $index < 25; ++$index) {
                $title = sprintf('WCP552-PAGE-%02d', $index);
                $published = $now->modify('-'.$index.' seconds')->format('Y-m-d H:i:s');
                $guideIds[] = $this->guide($connection, $gameId, null, null, 'published', $title, $published);
            }
            $slug = (string) $game->getSlug();
            $client->request('GET', '/gaming/guides?game='.$slug.'&q=WCP552-PAGE&type=build&page=1');
            self::assertResponseIsSuccessful();
            $first = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('WCP552-PAGE-00', $first);
            self::assertStringNotContainsString('WCP552-PAGE-24', $first);
            self::assertStringContainsString('game='.$slug, $first);
            self::assertStringContainsString('q=WCP552-PAGE', $first);
            self::assertStringContainsString('type=build', $first);

            $client->request('GET', '/gaming/guides?game='.$slug.'&q=WCP552-PAGE&type=build&page=2');
            self::assertResponseIsSuccessful();
            $second = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('WCP552-PAGE-24', $second);
            self::assertStringNotContainsString('WCP552-PAGE-00', $second);

            $client->request('GET', '/gaming/guides?game='.$slug.'&q=WCP552-PAGE&type=build&page=3');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, $guideIds, [], [$gameId]);
        }
    }

    public function testDisabledGamingHidesPublicGuideRoutes(): void
    {
        $client = static::createClient();
        $this->setModules($client, false);

        try {
            $client->request('GET', '/gaming/guides');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/gaming/guides/1');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->setModules($client, null);
        }
    }

    private function game(EntityManagerInterface $em, bool $enabled): Game
    {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())
            ->setName('Public guide game '.$suffix)
            ->setSlug('public-guide-game-'.$suffix)
            ->setEnabled($enabled);
        $em->persist($game);
        $em->flush();

        return $game;
    }

    private function user(EntityManagerInterface $em): User
    {
        $user = (new User())
            ->setEmail('guide-private-'.bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName('Guide private tester')
            ->setPermissions([CmsPermission::GAMING])
            ->verifyEmail();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function guide(
        Connection $connection,
        int $gameId,
        ?int $authorId,
        ?int $reviewerId,
        string $status,
        string $title,
        ?string $publishedAt = null,
    ): int {
        $publishedAt ??= (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s');

        return (int) $connection->fetchOne(
            'INSERT INTO game_guide (game_id, author_id, reviewer_id, title, guide_type, game_version, season, valid_from, valid_until, review_status, published_at)
             VALUES (:game, :author, :reviewer, :title, :type, :version, :season, :from, NULL, :status, :published) RETURNING id',
            [
                'game' => $gameId,
                'author' => $authorId,
                'reviewer' => $reviewerId,
                'title' => $title,
                'type' => 'build',
                'version' => '2.4',
                'season' => 'Test',
                'from' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
                'status' => $status,
                'published' => $status === 'published' ? $publishedAt : null,
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
