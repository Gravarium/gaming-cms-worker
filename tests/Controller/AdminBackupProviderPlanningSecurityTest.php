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
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminBackupProviderPlanningSecurityTest extends WebTestCase
{
    public function testCatalogueAndPlanRequireConnectorPermissionEvenWithValidCsrf(): void
    {
        $client = static::createClient();
        $providerKeys = $this->providerKeys();
        $baselineTargetKeys = $this->backupTargetKeys($client);
        $selectionBefore = $this->selectionFileState($client);
        $before = $this->plannerState($client);
        $user = $this->createUser($client, 'denied', [CmsPermission::CONTENT]);

        try {
            $client->loginUser($user);

            $client->request('GET', '/admin/connectors/backup-catalog');
            self::assertResponseStatusCodeSame(403);

            $csrfManager = $client->getContainer()->get(CsrfTokenManagerInterface::class);
            self::assertInstanceOf(CsrfTokenManagerInterface::class, $csrfManager);
            $validToken = $csrfManager->getToken('backup-provider-plan')->getValue();

            $client->request('POST', '/admin/connectors/backup-catalog/plan', [
                '_token' => $validToken,
                'providers' => $providerKeys,
            ]);
            self::assertResponseStatusCodeSame(403);

            self::assertSame($before, $this->plannerState($client));
            self::assertSame([], $this->auditEntries($client, $user));
        } finally {
            $this->cleanup($client, $user, $providerKeys, $baselineTargetKeys, $selectionBefore);
        }
    }

    public function testMissingAndInvalidPlanCsrfDoNotChangeBackupState(): void
    {
        $client = static::createClient();
        $providerKeys = $this->providerKeys();
        $baselineTargetKeys = $this->backupTargetKeys($client);
        $selectionBefore = $this->selectionFileState($client);
        $user = $this->createUser($client, 'csrf', [CmsPermission::CONNECTORS]);

        try {
            $client->loginUser($user);

            $crawler = $client->request('GET', '/admin/connectors/backup-catalog');
            self::assertResponseIsSuccessful();
            $token = (string) $crawler
                ->filter('form[action="/admin/connectors/backup-catalog/plan"] input[name="_token"]')
                ->attr('value');
            self::assertNotSame('', $token);

            $before = $this->plannerState($client);
            foreach ([[], ['_token' => 'invalid']] as $csrfParameters) {
                $client->request('POST', '/admin/connectors/backup-catalog/plan', [
                    'providers' => $providerKeys,
                    ...$csrfParameters,
                ]);

                self::assertResponseStatusCodeSame(403);
                self::assertSame($before, $this->plannerState($client));
                self::assertSame([], $this->auditEntries($client, $user));
            }
        } finally {
            $this->cleanup($client, $user, $providerKeys, $baselineTargetKeys, $selectionBefore);
        }
    }

    /**
     * @return list<string>
     */
    private function providerKeys(): array
    {
        return array_map(
            static fn (array $provider): string => $provider['key'],
            (new BackupProviderCatalog())->all(),
        );
    }

    /**
     * @return list<string>
     */
    private function backupTargetKeys(KernelBrowser $client): array
    {
        $targets = $this->entityManager($client)
            ->getRepository(ExternalConnectorTarget::class)
            ->findBy(['capability' => ExternalConnectorTarget::CAPABILITY_BACKUP]);
        $keys = [];

        foreach ($targets as $target) {
            if ($target instanceof ExternalConnectorTarget) {
                $keys[] = $target->getTargetKey();
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array{
     *     backup_targets: int,
     *     planner_audit: int,
     *     selection_exists: bool,
     *     selection_is_link: bool,
     *     selection_readable: bool,
     *     selection_sha256: string|null
     * }
     */
    private function plannerState(KernelBrowser $client): array
    {
        $entityManager = $this->entityManager($client);
        $selection = $this->selectionFileState($client);
        $contents = $selection['contents'];

        return [
            'backup_targets' => $entityManager
                ->getRepository(ExternalConnectorTarget::class)
                ->count(['capability' => ExternalConnectorTarget::CAPABILITY_BACKUP]),
            'planner_audit' => $entityManager
                ->getRepository(AuditLog::class)
                ->count(['action' => 'connector.backup_catalog.plan']),
            'selection_exists' => $selection['exists'],
            'selection_is_link' => $selection['is_link'],
            'selection_readable' => !$selection['exists'] || is_string($contents),
            'selection_sha256' => is_string($contents) ? hash('sha256', $contents) : null,
        ];
    }

    /**
     * @return array{
     *     path: string,
     *     exists: bool,
     *     is_link: bool,
     *     contents: string|false|null
     * }
     */
    private function selectionFileState(KernelBrowser $client): array
    {
        $path = $client->getContainer()->get(BackupSelectionSynchronizer::class)->outputFile();
        $exists = is_file($path);

        return [
            'path' => $path,
            'exists' => $exists,
            'is_link' => is_link($path),
            'contents' => $exists ? file_get_contents($path) : null,
        ];
    }

    /**
     * @return list<AuditLog>
     */
    private function auditEntries(KernelBrowser $client, User $user): array
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $userId = $user->getId();
        self::assertNotNull($userId);

        $storedUser = $entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $storedUser);
        $entries = $entityManager->getRepository(AuditLog::class)->findBy(['actor' => $storedUser]);

        return array_values(array_filter(
            $entries,
            static fn (object $entry): bool => $entry instanceof AuditLog,
        ));
    }

    /**
     * @param list<string> $providerKeys
     * @param list<string> $baselineTargetKeys
     * @param array{
     *     path: string,
     *     exists: bool,
     *     is_link: bool,
     *     contents: string|false|null
     * } $selectionBefore
     */
    private function cleanup(
        KernelBrowser $client,
        User $user,
        array $providerKeys,
        array $baselineTargetKeys,
        array $selectionBefore,
    ): void {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        foreach ($providerKeys as $providerKey) {
            $targetKey = 'backup-'.$providerKey;
            if (in_array($targetKey, $baselineTargetKeys, true)) {
                continue;
            }

            $target = $entityManager->getRepository(ExternalConnectorTarget::class)->findOneBy([
                'capability' => ExternalConnectorTarget::CAPABILITY_BACKUP,
                'targetKey' => $targetKey,
            ]);
            if ($target instanceof ExternalConnectorTarget) {
                $entityManager->remove($target);
            }
        }

        $userId = $user->getId();
        if ($userId !== null) {
            $storedUser = $entityManager->find(User::class, $userId);
            if ($storedUser instanceof User) {
                foreach ($entityManager->getRepository(AuditLog::class)->findBy(['actor' => $storedUser]) as $entry) {
                    if ($entry instanceof AuditLog) {
                        $entityManager->remove($entry);
                    }
                }
                $entityManager->remove($storedUser);
            }
        }

        $entityManager->flush();
        $entityManager->clear();
        $this->restoreSelectionFile($selectionBefore);
    }

    /**
     * @param array{
     *     path: string,
     *     exists: bool,
     *     is_link: bool,
     *     contents: string|false|null
     * } $baseline
     */
    private function restoreSelectionFile(array $baseline): void
    {
        $path = $baseline['path'];
        if ($baseline['is_link'] || is_link($path)) {
            return;
        }

        $exists = is_file($path);
        $contents = $exists ? file_get_contents($path) : null;
        if ($exists === $baseline['exists'] && $contents === $baseline['contents']) {
            return;
        }

        if (!$baseline['exists']) {
            if ($exists && !unlink($path)) {
                throw new RuntimeException('Could not remove the unexpected backup selection file.');
            }

            return;
        }

        if (!is_string($baseline['contents']) || !is_dir(dirname($path)) || is_link(dirname($path))) {
            return;
        }

        if (file_put_contents($path, $baseline['contents'], LOCK_EX) === false) {
            throw new RuntimeException('Could not restore the backup selection file after the security test.');
        }
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('backup-planning-security-'.$label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Backup planning security '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
