<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Backup\BackupProviderCatalog;
use App\Backup\BackupSelectionSynchronizer;
use App\Entity\AuditLog;
use App\Entity\ExternalConnectorTarget;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminBackupProviderPlanningBoundaryTest extends WebTestCase
{
    public function testOversizedProviderSelectionMakesNoChangesAndShowsNotice(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $user = (new User())
            ->setEmail('backup-plan-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Backup planner boundary test')
            ->setPermissions([CmsPermission::CONNECTORS])
            ->verifyEmail();
        $entityManager->persist($user);
        $entityManager->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/admin/connectors/backup-catalog');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler
            ->filter('form[action="/admin/connectors/backup-catalog/plan"] input[name="_token"]')
            ->attr('value');

        $providerKeys = array_map(
            static fn (array $provider): string => $provider['key'],
            (new BackupProviderCatalog())->all(),
        );
        $providerKeys[] = 'excess-provider';

        $beforeManager = $client->getContainer()->get(EntityManagerInterface::class);
        $beforeTargets = $beforeManager->getRepository(ExternalConnectorTarget::class)->count([
            'capability' => ExternalConnectorTarget::CAPABILITY_BACKUP,
        ]);
        $beforeAudit = $beforeManager->getRepository(AuditLog::class)->count([
            'action' => 'connector.backup_catalog.plan',
        ]);
        $selectionPath = $client->getContainer()->get(BackupSelectionSynchronizer::class)->outputFile();
        $beforeSelection = is_file($selectionPath) ? file_get_contents($selectionPath) : null;

        $client->request('POST', '/admin/connectors/backup-catalog/plan', [
            '_token' => $token,
            'providers' => $providerKeys,
        ]);

        self::assertResponseRedirects('/admin/connectors');
        $client->followRedirect();
        self::assertSelectorTextContains('.notice', 'Es wurden keine Backup-Ziele geändert.');

        $afterManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertSame($beforeTargets, $afterManager->getRepository(ExternalConnectorTarget::class)->count([
            'capability' => ExternalConnectorTarget::CAPABILITY_BACKUP,
        ]));
        self::assertSame($beforeAudit, $afterManager->getRepository(AuditLog::class)->count([
            'action' => 'connector.backup_catalog.plan',
        ]));
        $afterSelection = is_file($selectionPath) ? file_get_contents($selectionPath) : null;
        self::assertSame($beforeSelection, $afterSelection);
    }
}
