<?php

declare(strict_types=1);

namespace App\Tests\Backup;

use App\Backup\BackupProviderCatalog;
use PHPUnit\Framework\TestCase;

final class BackupProviderCatalogTest extends TestCase
{
    public function testCatalogContainsTwentyUniqueSecretFreePlanningChoices(): void
    {
        $providers = (new BackupProviderCatalog())->all();

        self::assertCount(20, $providers);
        self::assertCount(20, array_unique(array_column($providers, 'key')));
        self::assertContains('Google Drive', array_column($providers, 'name'));
        self::assertContains('pCloud', array_column($providers, 'name'));
        self::assertContains('Eigener Server per SFTP', array_column($providers, 'name'));

        foreach ($providers as $provider) {
            self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9.-]*$/', $provider['key']);
            self::assertNotSame('', $provider['transport']);
        }
    }
}
