<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Messenger\FailedMessageOverview;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class FailedMessageOverviewTest extends TestCase
{
    public function testExposesOnlyShortValidatedMessageType(): void
    {
        self::assertSame(
            'SendEmailMessage',
            FailedMessageOverview::sanitizedType(json_encode(['type' => 'Symfony\\Component\\Mailer\\Messenger\\SendEmailMessage'], JSON_THROW_ON_ERROR)),
        );
        self::assertSame('Unbekannte Nachricht', FailedMessageOverview::sanitizedType('{"type":"<script>"}'));
        self::assertSame('Unbekannte Nachricht', FailedMessageOverview::sanitizedType('not-json'));
    }

    public function testRejectsOversizedHeadersBeforeJsonDecoding(): void
    {
        $headers = json_encode([
            'type' => 'SafeMessage',
            'payload' => str_repeat('x', FailedMessageOverview::MAX_HEADER_BYTES),
        ], JSON_THROW_ON_ERROR);

        self::assertGreaterThan(FailedMessageOverview::MAX_HEADER_BYTES, strlen($headers));
        self::assertSame('Unbekannte Nachricht', FailedMessageOverview::sanitizedType($headers));
    }

    public function testRejectsExcessiveJsonDepth(): void
    {
        $headers = '{"type":"SafeMessage","nested":'.str_repeat('{"value":', 20).'0'.str_repeat('}', 20).'}';

        self::assertSame('Unbekannte Nachricht', FailedMessageOverview::sanitizedType($headers));
    }

    public function testReadBoundsHeaderProjectionBeforeRowsReachPhp(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturn(1);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains(
                    $sql,
                    'SUBSTR(headers, 1, '.(FailedMessageOverview::MAX_HEADER_BYTES + 1).') AS headers',
                )),
                ['failed'],
            )
            ->willReturn([[
                'id' => 'message-1',
                'headers' => json_encode(['type' => 'SendEmailMessage'], JSON_THROW_ON_ERROR),
                'created_at' => '2026-09-26 10:00:00',
                'body_size' => 123,
            ]]);

        $overview = new FailedMessageOverview($connection);
        $result = $overview->read(1);

        self::assertSame(1, $result['total']);
        self::assertSame('SendEmailMessage', $result['messages'][0]['type']);
        self::assertSame(123, $result['messages'][0]['bytes']);
    }
}
