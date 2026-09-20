<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ExternalConnectorTarget;
use PHPUnit\Framework\TestCase;

final class ExternalConnectorTargetTest extends TestCase
{
    public function testNormalizesNonSecretIdentifiers(): void
    {
        $target = (new ExternalConnectorTarget())
            ->setCapability(ExternalConnectorTarget::CAPABILITY_BACKUP)
            ->setTargetKey(' Google-Drive-1 ')
            ->setProviderKey(' Google-Drive ')
            ->setDisplayName(' Google Drive Sicherung ')
            ->setConfigurationReference(' Backup.Google-Drive-1 ')
            ->setPriority(10)
            ->setRequired(true)
            ->setEnabled(false);

        self::assertSame('google-drive-1', $target->getTargetKey());
        self::assertSame('google-drive', $target->getProviderKey());
        self::assertSame('Google Drive Sicherung', $target->getDisplayName());
        self::assertSame('backup.google-drive-1', $target->getConfigurationReference());
        self::assertTrue($target->isRequired());
        self::assertFalse($target->isEnabled());
        self::assertSame(10, $target->getPriority());
    }
}
