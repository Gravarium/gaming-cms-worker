<?php

declare(strict_types=1);

namespace App\Tests\Widget\Module;

use App\Widget\Module\ModuleWidgetGuildPresenceQuery;
use App\Widget\Module\ModuleWidgetViewer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ModuleWidgetGuildPresenceQueryTest extends TestCase
{
    public function testGuildIdsForUserAreBoundedAndOnlyActiveMembershipsAreReturned(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchFirstColumn')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, 'active = TRUE') && str_contains($sql, 'LIMIT :limit')),
                self::callback(static fn (array $parameters): bool => $parameters === ['user_id' => 7, 'limit' => 500]),
            )
            ->willReturn([4, '9', 4, 0, 'invalid']);

        self::assertSame([4, 9], (new ModuleWidgetGuildPresenceQuery($connection))->guildIdsForUser(7));
    }

    public function testQueryExcludesPrivateProfilesAndOtherGuilds(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE game (id INTEGER PRIMARY KEY, name TEXT NOT NULL, enabled BOOLEAN NOT NULL)');
        $connection->executeStatement('CREATE TABLE guild (id INTEGER PRIMARY KEY, name TEXT NOT NULL, game_id INTEGER NOT NULL, enabled BOOLEAN NOT NULL)');
        $connection->executeStatement('CREATE TABLE cms_user (id INTEGER PRIMARY KEY, display_name TEXT NOT NULL, is_active BOOLEAN NOT NULL, locked_until TEXT, last_seen_at TEXT)');
        $connection->executeStatement('CREATE TABLE guild_member (user_id INTEGER NOT NULL, guild_id INTEGER NOT NULL, active BOOLEAN NOT NULL)');
        $connection->executeStatement('CREATE TABLE member_profile (user_id INTEGER PRIMARY KEY, visibility TEXT NOT NULL)');
        $connection->executeStatement('INSERT INTO game (id, name, enabled) VALUES (1, \'Game\', TRUE)');
        $connection->executeStatement('INSERT INTO guild (id, name, game_id, enabled) VALUES (7, \'Visible guild\', 1, TRUE), (8, \'Other guild\', 1, TRUE)');
        $connection->executeStatement('INSERT INTO cms_user (id, display_name, is_active, locked_until, last_seen_at) VALUES (1, \'Viewer\', TRUE, NULL, \'2026-09-29 11:50:00\'), (2, \'Visible member\', TRUE, NULL, \'2026-09-29 11:59:00\'), (3, \'Private member\', TRUE, NULL, \'2026-09-29 11:59:00\'), (4, \'Other guild member\', TRUE, NULL, \'2026-09-29 11:59:00\')');
        $connection->executeStatement('INSERT INTO guild_member (user_id, guild_id, active) VALUES (1, 7, TRUE), (2, 7, TRUE), (3, 7, TRUE), (4, 8, TRUE)');
        $connection->executeStatement('INSERT INTO member_profile (user_id, visibility) VALUES (3, \'{"display_name":"private"}\')');

        $payload = (new ModuleWidgetGuildPresenceQuery($connection))->forViewer(
            new ModuleWidgetViewer(true, userId: 1, guildIds: [7]),
            new \DateTimeImmutable('2026-09-29 12:00:00'),
            12,
        );

        self::assertSame('ready', $payload->status);
        self::assertCount(1, $payload->items);
        self::assertSame('Visible member', $payload->items[0]['title']);
    }

    public function testAnonymousOrUnscopedViewersNeverQueryPresence(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAllAssociative');
        $query = new ModuleWidgetGuildPresenceQuery($connection);
        $now = new \DateTimeImmutable('2026-09-29T00:00:00Z');

        self::assertSame('empty', $query->forViewer(ModuleWidgetViewer::anonymous(), $now, 6)->status);
        self::assertSame(
            'empty',
            $query->forViewer(new ModuleWidgetViewer(true), $now, 6)->status,
        );
        self::assertSame(
            'empty',
            $query->forViewer(new ModuleWidgetViewer(true, guildIds: [4]), $now, 6)->status,
        );
    }

    public function testPresenceQueryIsBoundedToCurrentViewerMembershipsAndOmitsPrivateFields(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(static function (string $sql): bool {
                    self::assertStringContainsString('gm.guild_id IN (:guild_0)', $sql);
                    self::assertStringContainsString('gm.active = TRUE', $sql);
                    self::assertStringContainsString('viewer_membership.user_id = :viewer_user_id', $sql);
                    self::assertStringContainsString('user_row.is_active = TRUE', $sql);
                    self::assertStringContainsString('user_row.last_seen_at >= :cutoff', $sql);
                    self::assertStringContainsString('locked_until IS NULL', $sql);
                    self::assertStringContainsString('profile.visibility', $sql);
                    self::assertStringContainsString('display_name', $sql);
                    self::assertStringNotContainsString('email', $sql);
                    self::assertStringNotContainsString('two_factor_secret', $sql);

                    return true;
                }),
                self::callback(static function (array $parameters): bool {
                    self::assertSame(4, $parameters['guild_0']);
                    self::assertSame(7, $parameters['viewer_user_id']);
                    self::assertSame(12, $parameters['limit']);

                    return true;
                }),
            )
            ->willReturn([[
                'display_name' => 'Guild member',
                'guild_name' => 'Guild',
                'game_name' => 'Game',
            ]]);

        $payload = (new ModuleWidgetGuildPresenceQuery($connection))->forViewer(
            new ModuleWidgetViewer(true, userId: 7, guildIds: [4]),
            new \DateTimeImmutable('2026-09-29T00:00:00Z'),
            99,
        );

        self::assertSame('ready', $payload->status);
        self::assertSame(['title' => 'Guild member', 'summary' => 'Guild', 'eyebrow' => 'Game', 'badge' => 'Jetzt aktiv'], $payload->items[0]);
    }
}
