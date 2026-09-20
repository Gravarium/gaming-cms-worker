<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use App\Messenger\FailedMessageOverview;
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
}
