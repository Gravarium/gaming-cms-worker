<?php

declare(strict_types=1);

namespace App\Tests\Widget\Module;

use App\Widget\Module\ModuleWidgetServerStatusQuery;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ModuleWidgetServerStatusQueryTest extends TestCase
{
    public function testReturnsNewestUnexpiredStatusFromCurrentTrustedCache(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE game (id INTEGER PRIMARY KEY, name TEXT NOT NULL, enabled BOOLEAN NOT NULL)');
        $connection->executeStatement('CREATE TABLE game_server_registry (id INTEGER PRIMARY KEY, game_id INTEGER NOT NULL, name TEXT NOT NULL, maintenance BOOLEAN NOT NULL, enabled BOOLEAN NOT NULL)');
        $connection->executeStatement('CREATE TABLE game_server_status (id INTEGER PRIMARY KEY, server_id INTEGER NOT NULL, state TEXT NOT NULL, players INTEGER NOT NULL, slots INTEGER NOT NULL, ping_ms INTEGER, map_name TEXT, server_version TEXT, observed_at TEXT NOT NULL, expires_at TEXT NOT NULL)');
        $connection->executeStatement('INSERT INTO game (id, name, enabled) VALUES (1, \'Game\', TRUE)');
        $connection->executeStatement('INSERT INTO game_server_registry (id, game_id, name, maintenance, enabled) VALUES (1, 1, \'Arena\', FALSE, TRUE)');
        $connection->executeStatement('INSERT INTO game_server_status (id, server_id, state, players, slots, ping_ms, map_name, server_version, observed_at, expires_at) VALUES (1, 1, \'offline\', 0, 20, NULL, NULL, NULL, \'2026-09-29 11:55:00\', \'2026-09-29 12:00:00\')');
        $connection->executeStatement('INSERT INTO game_server_status (id, server_id, state, players, slots, ping_ms, map_name, server_version, observed_at, expires_at) VALUES (2, 1, \'online\', 8, 20, 42, \'Citadel\', \'1.2\', \'2026-09-29 11:59:00\', \'2026-09-29 12:10:00\')');

        $payload = (new ModuleWidgetServerStatusQuery($connection))->latest(
            new \DateTimeImmutable('2026-09-29 12:00:00'),
            6,
        );

        self::assertSame('ready', $payload->status);
        self::assertSame('Arena', $payload->items[0]['title']);
        self::assertStringContainsString('Online', $payload->items[0]['summary']);
        self::assertStringContainsString('8/20 Spieler', $payload->items[0]['summary']);
        self::assertStringContainsString('Citadel', $payload->items[0]['summary']);
    }

    public function testReturnsBoundedPublicStatusWithoutNetworkTargets(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(static function (string $sql): bool {
                    self::assertStringContainsString('game_server_registry', $sql);
                    self::assertStringContainsString('game_server_status', $sql);
                    self::assertStringContainsString('expires_at > :now', $sql);
                    self::assertStringContainsString('registry.enabled = TRUE', $sql);
                    self::assertStringContainsString('game.enabled = TRUE', $sql);
                    self::assertStringNotContainsString('public_host', $sql);
                    self::assertStringNotContainsString('public_port', $sql);

                    return true;
                }),
                self::callback(static fn (array $parameters): bool => $parameters['limit'] === 6),
            )
            ->willReturn([[
                'server_name' => 'Arena',
                'maintenance' => true,
                'game_name' => 'Example Game',
                'state' => 'online',
                'players' => 8,
                'slots' => 20,
                'ping_ms' => 42,
                'map_name' => 'Citadel',
                'server_version' => '1.2',
            ]]);

        $payload = (new ModuleWidgetServerStatusQuery($connection))->latest(
            new \DateTimeImmutable('2026-09-29T00:00:00Z'),
            6,
        );

        self::assertSame('ready', $payload->status);
        self::assertSame('Arena', $payload->items[0]['title']);
        self::assertSame('Example Game', $payload->items[0]['eyebrow']);
        self::assertSame('Wartung', $payload->items[0]['badge']);
        self::assertStringContainsString('8/20 Spieler', $payload->items[0]['summary']);
        self::assertStringContainsString('42 ms', $payload->items[0]['summary']);
        self::assertArrayNotHasKey('host', $payload->items[0]);
    }
}
