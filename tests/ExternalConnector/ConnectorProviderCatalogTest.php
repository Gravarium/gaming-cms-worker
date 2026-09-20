<?php

declare(strict_types=1);

namespace App\Tests\ExternalConnector;

use App\Entity\ExternalConnectorTarget;
use App\ExternalConnector\ConnectorProviderCatalog;
use PHPUnit\Framework\TestCase;

final class ConnectorProviderCatalogTest extends TestCase
{
    public function testCatalogCoversFiveCapabilitiesWithUniqueSafeChoices(): void
    {
        $catalog = new ConnectorProviderCatalog();
        $groups = $catalog->grouped();

        self::assertSame([
            ExternalConnectorTarget::CAPABILITY_MAIL,
            ExternalConnectorTarget::CAPABILITY_NOTIFICATIONS,
            ExternalConnectorTarget::CAPABILITY_IDENTITY,
            ExternalConnectorTarget::CAPABILITY_CDN,
            ExternalConnectorTarget::CAPABILITY_ANALYTICS,
        ], array_keys($groups));
        self::assertCount(29, $catalog->indexed());

        foreach ($catalog->indexed() as $choice => $provider) {
            self::assertSame($provider['capability'].':'.$provider['key'], $choice);
            self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9.-]*$/', $provider['key']);
            self::assertNotSame('', $provider['name']);
            self::assertNotSame('', $provider['family']);
        }
    }
}
