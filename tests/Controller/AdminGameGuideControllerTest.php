<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\Game;
use App\Entity\User;
use App\Gaming\Guide\BuildComponent;
use App\Gaming\Guide\StructuredBuild;
use App\Security\CmsPermission;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminGameGuideControllerTest extends WebTestCase
{
    public function testAuthorSubmitsStructuredBuildAndIndependentReviewerPublishesIt(): void
    {
        $client = static::createClient();
        $this->setModules($client, true);
        $em = $this->em($client);
        $connection = $this->connection($client);
        $game = $this->game($em);
        $author = $this->user($em, CmsPermission::GAMING, 'author');
        $reviewer = $this->user($em, CmsPermission::GAMING, 'reviewer');
        $gameId = $game->getId();
        $authorId = $author->getId();
        $reviewerId = $reviewer->getId();
        self::assertNotNull($gameId);
        self::assertNotNull($authorId);
        self::assertNotNull($reviewerId);
        $guideIds = [];

        try {
            $build = new StructuredBuild();
            $build->add(new BuildComponent('skill', 'arcane-barrage', 1, ['fire-bolt']));
            $client->loginUser($author);
            $crawler = $client->request('GET', '/admin/gaming/guides/new');
            $form = $crawler->selectButton('Entwurf speichern')->form([
                'title' => 'Guide <script>alert(1)</script>',
                'game_id' => (string) $gameId,
                'guide_type' => 'build',
                'game_version' => '2.4.1',
                'season' => 'Autumn',
                'valid_from' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
                'valid_until' => '',
                'build_code' => $build->exportCode(),
            ]);
            $client->submit($form);

            self::assertResponseRedirects();
            $id = (int) $connection->fetchOne('SELECT id FROM game_guide WHERE title = :title', [
                'title' => 'Guide <script>alert(1)</script>',
            ]);
            self::assertGreaterThan(0, $id);
            $guideIds[] = $id;

            $crawler = $client->request('GET', '/admin/gaming/guides/'.$id.'/edit');
            $editForm = $crawler->selectButton('Entwurf speichern')->form(['title' => 'Guide <script>alert(1)</script> updated']);
            $client->submit($editForm);
            self::assertResponseRedirects('/admin/gaming/guides');
            $row = $connection->fetchAssociative('SELECT review_status, author_id, title FROM game_guide WHERE id = :id', ['id' => $id]);
            self::assertIsArray($row);
            self::assertSame('draft', $row['review_status']);
            self::assertSame($authorId, (int) $row['author_id']);
            self::assertSame('Guide <script>alert(1)</script> updated', $row['title']);
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM game_guide_component WHERE guide_id = :id', ['id' => $id]));

            $client->request('POST', '/admin/gaming/guides/'.$id.'/submit');
            self::assertResponseStatusCodeSame(403);
            self::assertSame('draft', $connection->fetchOne('SELECT review_status FROM game_guide WHERE id = :id', ['id' => $id]));

            $crawler = $client->request('GET', '/admin/gaming/guides');
            $submitForm = $crawler->filter('form[action="/admin/gaming/guides/'.$id.'/submit"]')->form();
            $client->submit($submitForm);
            self::assertResponseRedirects('/admin/gaming/guides');
            self::assertSame('review', $connection->fetchOne('SELECT review_status FROM game_guide WHERE id = :id', ['id' => $id]));

            $reviewUrl = '/admin/gaming/guides/'.$id.'/review';
            $crawler = $client->request('GET', $reviewUrl);
            $forgedReview = $crawler->selectButton('Veröffentlichen')->form(['reason' => 'Forged token']);
            $forgedReview['_token'] = 'forged';
            $client->submit($forgedReview);
            self::assertResponseStatusCodeSame(403);
            self::assertSame('review', $connection->fetchOne('SELECT review_status FROM game_guide WHERE id = :id', ['id' => $id]));

            $crawler = $client->request('GET', $reviewUrl);
            $form = $crawler->selectButton('Veröffentlichen')->form(['reason' => 'Author cannot approve own work']);
            $client->submit($form);
            self::assertResponseStatusCodeSame(403);
            self::assertSame('review', $connection->fetchOne('SELECT review_status FROM game_guide WHERE id = :id', ['id' => $id]));

            $client->loginUser($reviewer);
            $crawler = $client->request('GET', $reviewUrl);
            $form = $crawler->selectButton('Veröffentlichen')->form(['reason' => 'Reviewed and approved']);
            $client->submit($form);
            self::assertResponseRedirects('/admin/gaming/guides');

            $published = $connection->fetchAssociative(
                'SELECT review_status, author_id, reviewer_id, published_at FROM game_guide WHERE id = :id',
                ['id' => $id],
            );
            self::assertIsArray($published);
            self::assertSame('published', $published['review_status']);
            self::assertSame($authorId, (int) $published['author_id']);
            self::assertSame($reviewerId, (int) $published['reviewer_id']);
            self::assertNotNull($published['published_at']);
            self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM game_guide_review_audit WHERE guide_id = :id', ['id' => $id]));

            $client->request('GET', '/gaming/guides/'.$id);
            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; updated', $html);
            self::assertStringNotContainsString('<script>alert(1)</script>', $html);
            self::assertStringContainsString('arcane-barrage', $html);
            self::assertStringNotContainsString($author->getEmail(), $html);
        } finally {
            $this->cleanup($client, $guideIds, [$authorId, $reviewerId], [$gameId]);
        }
    }

    public function testAuthorCanCreateEditAndPublishTierList(): void
    {
        $client = static::createClient();
        $this->setModules($client, true);
        $em = $this->em($client);
        $connection = $this->connection($client);
        $game = $this->game($em);
        $author = $this->user($em, CmsPermission::GAMING, 'tier-author');
        $reviewer = $this->user($em, CmsPermission::GAMING, 'tier-reviewer');
        $gameId = $game->getId();
        $authorId = $author->getId();
        $reviewerId = $reviewer->getId();
        self::assertNotNull($gameId);
        self::assertNotNull($authorId);
        self::assertNotNull($reviewerId);
        $guideIds = [];
        $title = 'WCP552 tier list '.bin2hex(random_bytes(4));

        try {
            $fields = [
                'title' => $title,
                'game_id' => (string) $gameId,
                'guide_type' => 'tier_list',
                'game_version' => '4.2',
                'season' => 'Season 4',
                'valid_from' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
                'valid_until' => '',
                'build_code' => '',
                'tier_criteria' => 'Solo ranked viability',
                'tier_provenance' => 'Synthetic review fixture',
                'tier_entries_json' => json_encode([
                    ['key' => 'arcane-barrage', 'tier' => 'S', 'reason' => 'Strong burst damage.'],
                ], JSON_THROW_ON_ERROR),
            ];

            $client->loginUser($author);
            $crawler = $client->request('GET', '/admin/gaming/guides/new');
            $form = $crawler->selectButton('Entwurf speichern')->form($fields);
            $client->submit($form);
            self::assertResponseRedirects();

            $id = (int) $connection->fetchOne('SELECT id FROM game_guide WHERE title = :title', ['title' => $title]);
            self::assertGreaterThan(0, $id);
            $guideIds[] = $id;
            $entry = $connection->fetchAssociative(
                'SELECT entry_key, tier, reason, criteria, provenance FROM game_guide_tier_entry WHERE guide_id = :id',
                ['id' => $id],
            );
            self::assertIsArray($entry);
            self::assertSame('arcane-barrage', $entry['entry_key']);
            self::assertSame('S', $entry['tier']);
            self::assertSame('Strong burst damage.', $entry['reason']);
            self::assertSame('Solo ranked viability', $entry['criteria']);
            self::assertSame('Synthetic review fixture', $entry['provenance']);

            $crawler = $client->request('GET', '/admin/gaming/guides/'.$id.'/edit');
            self::assertStringContainsString('arcane-barrage', (string) $client->getResponse()->getContent());
            $editForm = $crawler->selectButton('Entwurf speichern')->form(['title' => $title.' revised']);
            $client->submit($editForm);
            self::assertResponseRedirects('/admin/gaming/guides');
            self::assertSame(1, (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM game_guide_tier_entry WHERE guide_id = :id AND entry_key = :key',
                ['id' => $id, 'key' => 'arcane-barrage'],
            ));

            $crawler = $client->request('GET', '/admin/gaming/guides');
            $submitForm = $crawler->filter('form[action="/admin/gaming/guides/'.$id.'/submit"]')->form();
            $client->submit($submitForm);
            self::assertResponseRedirects('/admin/gaming/guides');

            $client->loginUser($reviewer);
            $crawler = $client->request('GET', '/admin/gaming/guides/'.$id.'/review');
            self::assertSelectorTextContains('main', 'Solo ranked viability');
            self::assertSelectorTextContains('main', 'arcane-barrage');
            $reviewForm = $crawler->selectButton('Veröffentlichen')->form(['reason' => 'Tier-list data reviewed.']);
            $client->submit($reviewForm);
            self::assertResponseRedirects('/admin/gaming/guides');
            self::assertSame('published', $connection->fetchOne('SELECT review_status FROM game_guide WHERE id = :id', ['id' => $id]));
            self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM game_guide_review_audit WHERE guide_id = :id', ['id' => $id]));

            $client->request('GET', '/gaming/guides/'.$id);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Solo ranked viability');
            self::assertSelectorTextContains('main', 'Synthetic review fixture');
            self::assertSelectorTextContains('main', 'Strong burst damage.');
        } finally {
            $this->cleanup($client, $guideIds, [$authorId, $reviewerId], [$gameId]);
        }
    }

    public function testOversizedAndDuplicateGuidePayloadsAreRejectedWithoutWrites(): void
    {
        $client = static::createClient();
        $this->setModules($client, true);
        $em = $this->em($client);
        $connection = $this->connection($client);
        $game = $this->game($em);
        $author = $this->user($em, CmsPermission::GAMING, 'oversize-author');
        $gameId = $game->getId();
        $authorId = $author->getId();
        self::assertNotNull($gameId);
        self::assertNotNull($authorId);

        try {
            $client->loginUser($author);
            $base = [
                'title' => '',
                'game_id' => (string) $gameId,
                'guide_type' => 'tier_list',
                'game_version' => '4.2',
                'season' => 'Season 4',
                'valid_from' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d'),
                'valid_until' => '',
                'build_code' => '',
                'tier_criteria' => 'Valid criteria',
                'tier_provenance' => 'Valid provenance',
                'tier_entries_json' => json_encode([
                    ['key' => 'arcane-barrage', 'tier' => 'S', 'reason' => 'Strong burst damage.'],
                ], JSON_THROW_ON_ERROR),
            ];
            $invalidCases = [
                array_replace($base, [
                    'title' => 'WCP552 duplicate tiers '.bin2hex(random_bytes(4)),
                    'tier_entries_json' => json_encode([
                        ['key' => 'arcane-barrage', 'tier' => 'S', 'reason' => 'First ranking.'],
                        ['key' => 'arcane-barrage', 'tier' => 'A', 'reason' => 'Duplicate ranking.'],
                    ], JSON_THROW_ON_ERROR),
                ]),
                array_replace($base, [
                    'title' => 'WCP552 oversized tier data '.bin2hex(random_bytes(4)),
                    'tier_entries_json' => str_repeat(' ', 32_001),
                ]),
                array_replace($base, [
                    'title' => 'WCP552 oversized build code '.bin2hex(random_bytes(4)),
                    'guide_type' => 'build',
                    'build_code' => str_repeat('A', 50_001),
                ]),
            ];

            foreach ($invalidCases as $fields) {
                $crawler = $client->request('GET', '/admin/gaming/guides/new');
                $form = $crawler->selectButton('Entwurf speichern')->form($fields);
                $client->submit($form);
                self::assertResponseStatusCodeSame(422);
                self::assertSame(0, (int) $connection->fetchOne(
                    'SELECT COUNT(*) FROM game_guide WHERE title = :title',
                    ['title' => $fields['title']],
                ));
            }
        } finally {
            $this->cleanup($client, [], [$authorId], [$gameId]);
        }
    }

    public function testDisabledGamingHidesGuideAdministration(): void
    {
        $client = static::createClient();
        $this->setModules($client, false);
        $em = $this->em($client);
        $author = $this->user($em, CmsPermission::GAMING, 'disabled-author');
        $authorId = $author->getId();
        self::assertNotNull($authorId);

        try {
            $client->loginUser($author);
            $client->request('GET', '/admin/gaming/guides');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($client, [], [$authorId], []);
        }
    }

    public function testReviewerCanReturnSubmissionToItsAuthor(): void
    {
        $client = static::createClient();
        $this->setModules($client, true);
        $em = $this->em($client);
        $connection = $this->connection($client);
        $game = $this->game($em);
        $author = $this->user($em, CmsPermission::GAMING, 'reject-author');
        $reviewer = $this->user($em, CmsPermission::GAMING, 'reject-reviewer');
        $gameId = $game->getId();
        $authorId = $author->getId();
        $reviewerId = $reviewer->getId();
        self::assertNotNull($gameId);
        self::assertNotNull($authorId);
        self::assertNotNull($reviewerId);
        $guideIds = [];

        try {
            $id = $this->guide($connection, $gameId, $authorId, 'review', 'Needs changes');
            $guideIds[] = $id;
            $connection->insert('game_guide_review_audit', [
                'guide_id' => $id,
                'actor_id' => $authorId,
                'status' => 'review',
                'reason' => 'Submitted for review',
                'occurred_at' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            ]);

            $client->loginUser($reviewer);
            $crawler = $client->request('GET', '/admin/gaming/guides/'.$id.'/review');
            $form = $crawler->selectButton('Zur Überarbeitung zurückgeben')->form(['reason' => 'Add a clearer rationale']);
            $client->submit($form);
            self::assertResponseRedirects('/admin/gaming/guides');

            $row = $connection->fetchAssociative(
                'SELECT review_status, reviewer_id, published_at FROM game_guide WHERE id = :id',
                ['id' => $id],
            );
            self::assertIsArray($row);
            self::assertSame('draft', $row['review_status']);
            self::assertSame($reviewerId, (int) $row['reviewer_id']);
            self::assertNull($row['published_at']);
            self::assertSame(1, (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM game_guide_review_audit WHERE guide_id = :id AND status = 'draft'",
                ['id' => $id],
            ));
        } finally {
            $this->cleanup($client, $guideIds, [$authorId, $reviewerId], [$gameId]);
        }
    }

    public function testMissingPermissionAndInvalidOrForgedCreateDoNotWrite(): void
    {
        $client = static::createClient();
        $this->setModules($client, true);
        $em = $this->em($client);
        $connection = $this->connection($client);
        $game = $this->game($em);
        $reader = $this->user($em, CmsPermission::CONTENT, 'reader');
        $author = $this->user($em, CmsPermission::GAMING, 'invalid-author');
        $gameId = $game->getId();
        $readerId = $reader->getId();
        $authorId = $author->getId();
        self::assertNotNull($gameId);
        self::assertNotNull($readerId);
        self::assertNotNull($authorId);
        $guideIds = [];

        try {
            $client->loginUser($reader);
            $client->request('GET', '/admin/gaming/guides/new');
            self::assertResponseStatusCodeSame(403);

            $client->loginUser($author);
            $fields = [
                'title' => 'Invalid build code',
                'game_id' => (string) $gameId,
                'guide_type' => 'build',
                'game_version' => '2.4',
                'season' => 'Winter',
                'valid_from' => (new \DateTimeImmutable())->format('Y-m-d'),
                'valid_until' => '',
                'build_code' => 'not-a-build-code',
            ];
            $client->request('POST', '/admin/gaming/guides/new', $fields + ['_token' => 'forged']);
            self::assertResponseStatusCodeSame(403);
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM game_guide WHERE title = :title', ['title' => $fields['title']]));

            $crawler = $client->request('GET', '/admin/gaming/guides/new');
            $form = $crawler->selectButton('Entwurf speichern')->form($fields);
            $client->submit($form);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM game_guide WHERE title = :title', ['title' => $fields['title']]));
        } finally {
            $this->cleanup($client, $guideIds, [$readerId, $authorId], [$gameId]);
        }
    }

    private function game(EntityManagerInterface $em): Game
    {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())->setName('Guide game '.$suffix)->setSlug('guide-game-'.$suffix);
        $em->persist($game);
        $em->flush();

        return $game;
    }

    private function user(EntityManagerInterface $em, string $permission, string $label): User
    {
        $user = (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName('Guide '.$label)
            ->setPermissions([$permission])
            ->verifyEmail();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function guide(Connection $connection, int $gameId, int $authorId, string $status, string $title): int
    {
        return (int) $connection->fetchOne(
            'INSERT INTO game_guide (game_id, author_id, reviewer_id, title, guide_type, game_version, season, valid_from, valid_until, review_status, published_at)
             VALUES (:game, :author, NULL, :title, :type, :version, :season, :from, NULL, :status, NULL) RETURNING id',
            [
                'game' => $gameId,
                'author' => $authorId,
                'title' => $title,
                'type' => 'build',
                'version' => '2.4',
                'season' => 'Test',
                'from' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'),
                'status' => $status,
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
