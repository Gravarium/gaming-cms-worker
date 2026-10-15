<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Messenger\FailedMessageOverview;
use App\Messenger\FailedMessagePaginator;
use App\Messenger\FailedMessagePayloadSizeSql;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\TestCase;

final class FailedMessagePayloadBytesTest extends TestCase
{
    public function testBothQueueReadersReturnUtf8PayloadByteLengthsWithoutReturningBodies(): void
    {
        $connection = DriverManager::getConnection(['url' => 'sqlite:///:memory:']);

        try {
            $connection->executeStatement(
                'CREATE TABLE messenger_messages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    body TEXT NOT NULL,
                    headers TEXT NOT NULL,
                    queue_name VARCHAR(190) NOT NULL,
                    created_at DATETIME NOT NULL,
                    available_at DATETIME NOT NULL,
                    delivered_at DATETIME DEFAULT NULL
                )',
            );

            $payloads = [
                'QueueBytesAsciiMessage' => 'plain ASCII payload',
                'QueueBytesUnicodeMessage' => 'café 🎮 payload',
            ];

            foreach ($payloads as $type => $body) {
                $connection->insert('messenger_messages', [
                    'body' => $body,
                    'headers' => json_encode(['type' => $type], JSON_THROW_ON_ERROR),
                    'queue_name' => 'failed',
                    'created_at' => '2026-10-14 12:00:00',
                    'available_at' => '2026-10-14 12:00:00',
                    'delivered_at' => null,
                ]);
            }

            $readers = [
                (new FailedMessageOverview($connection))->read()['messages'],
                (new FailedMessagePaginator($connection))->read()['messages'],
            ];

            foreach ($readers as $messages) {
                self::assertCount(2, $messages);
                foreach ($messages as $message) {
                    self::assertSame(strlen($payloads[$message['type']]), $message['bytes']);
                    self::assertSame(['id', 'type', 'createdAt', 'bytes'], array_keys($message));
                }
            }
        } finally {
            $connection->close();
        }
    }

    public function testUsesByteLengthExpressionsForSupportedDatabasePlatforms(): void
    {
        self::assertSame(
            'LENGTH(CAST(body AS BLOB))',
            FailedMessagePayloadSizeSql::expression(new SQLitePlatform()),
        );
        self::assertSame(
            'OCTET_LENGTH(body)',
            FailedMessagePayloadSizeSql::expression(new PostgreSQLPlatform()),
        );
    }
}
