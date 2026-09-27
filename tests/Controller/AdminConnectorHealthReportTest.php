<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\ExternalConnectorHealthStatus;
use App\Entity\ExternalConnectorTarget;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AdminConnectorHealthReportTest extends WebTestCase
{
    public function testAuthorizedOperatorReceivesFilteredSanitizedHealthReport(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $suffix = bin2hex(random_bytes(5));
        $healthy = $this->target('mail', 'health-mail-'.$suffix, 'Health mail '.$suffix);
        $failed = $this->target('notifications', 'health-notify-'.$suffix, 'Health notifications '.$suffix);
        $pending = $this->target('analytics', 'health-analytics-'.$suffix, 'Health analytics '.$suffix);
        $healthyStatus = (new ExternalConnectorHealthStatus())
            ->setCapability($healthy->getCapability())
            ->setTargetKey($healthy->getTargetKey())
            ->setProviderKey($healthy->getProviderKey())
            ->setSuccessful(true)
            ->setCheckedAt(new \DateTimeImmutable('2026-09-27 09:00:00 UTC'));
        $failedStatus = (new ExternalConnectorHealthStatus())
            ->setCapability($failed->getCapability())
            ->setTargetKey($failed->getTargetKey())
            ->setProviderKey($failed->getProviderKey())
            ->setSuccessful(false)
            ->setCheckedAt(new \DateTimeImmutable('2026-09-27 09:05:00 UTC'));
        foreach ([$healthy, $failed, $pending, $healthyStatus, $failedStatus] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $user = $this->user($client, [CmsPermission::CONNECTORS]);
        $client->loginUser($user);

        try {
            $client->request('GET', '/admin/connectors/health/report?capability=notifications&status=failed');

            self::assertResponseIsSuccessful();
            self::assertStringContainsString('private', (string) $client->getResponse()->headers->get('Cache-Control'));
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            $payload = $this->payload($client);
            self::assertSame(['capability' => 'notifications', 'status' => 'failed'], $payload['filters']);
            self::assertGreaterThanOrEqual(1, $payload['counts']['total']);
            self::assertGreaterThanOrEqual(1, $payload['counts']['failed']);
            $failedKey = $failed->getTargetKey();
            $failedRows = array_values(array_filter(
                $payload['targets'],
                static fn (mixed $row): bool => is_array($row) && ($row['targetKey'] ?? null) === $failedKey,
            ));
            self::assertCount(1, $failedRows);
            self::assertSame('failed', $failedRows[0]['status']);
            self::assertSame('2026-09-27T09:05:00+00:00', $failedRows[0]['checkedAt']);
            self::assertArrayNotHasKey('configurationReference', $failedRows[0]);
            self::assertArrayNotHasKey('backupId', $failedRows[0]);

            $client->request('GET', '/admin/connectors/health/report?capability=analytics');
            self::assertResponseIsSuccessful();
            $payload = $this->payload($client);
            self::assertGreaterThanOrEqual(1, $payload['counts']['pending']);
            $pendingKey = $pending->getTargetKey();
            $pendingRows = array_values(array_filter(
                $payload['targets'],
                static fn (mixed $row): bool => is_array($row) && ($row['targetKey'] ?? null) === $pendingKey,
            ));
            self::assertCount(1, $pendingRows);
            self::assertNull($pendingRows[0]['checkedAt']);
        } finally {
            $this->removeById($em, ExternalConnectorHealthStatus::class, $healthyStatus->getId());
            $this->removeById($em, ExternalConnectorHealthStatus::class, $failedStatus->getId());
            $this->removeById($em, ExternalConnectorTarget::class, $healthy->getId());
            $this->removeById($em, ExternalConnectorTarget::class, $failed->getId());
            $this->removeById($em, ExternalConnectorTarget::class, $pending->getId());
            $this->removeUser($em, $user);
            $em->flush();
        }
    }

    public function testInvalidFilterReturnsReadableBadRequest(): void
    {
        $client = static::createClient();
        $user = $this->user($client, [CmsPermission::CONNECTORS]);
        $client->loginUser($user);

        try {
            $client->request('GET', '/admin/connectors/health/report?status=unknown');

            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
            $payload = $this->payload($client);
            self::assertSame('status', $payload['parameter']);
            self::assertStringContainsString('Ungültiger status-Filter', $payload['error']);
            self::assertContains('pending', $payload['allowed']);

            $client->request('GET', '/admin/connectors/health/report?status%5B%5D=failed');
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
            $payload = $this->payload($client);
            self::assertSame('status', $payload['parameter']);
        } finally {
            $em = $this->em($client);
            $this->removeUser($em, $user);
            $em->flush();
        }
    }

    public function testAnonymousAndUnprivilegedOperatorsCannotReadReport(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/connectors/health/report');
        self::assertResponseRedirects('/login');

        $user = $this->user($client, [CmsPermission::ACCESS]);
        $client->loginUser($user);
        try {
            $client->request('GET', '/admin/connectors/health/report');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            $em = $this->em($client);
            $this->removeUser($em, $user);
            $em->flush();
        }
    }

    public function testDisabledIntegrationsModuleHidesReport(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $state = $em->find(CmsModuleState::class, 'integrations');
        $createdState = $state === null;
        $wasEnabled = $state?->isEnabled() ?? true;
        $state ??= (new CmsModuleState())
            ->setModuleKey('integrations')
            ->updateVersion('1.0.0');
        $state->setEnabled(false);
        $em->persist($state);
        $em->flush();
        $user = $this->user($client, [CmsPermission::CONNECTORS]);
        $client->loginUser($user);

        try {
            $client->request('GET', '/admin/connectors/health/report');
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        } finally {
            $this->removeUser($em, $user);
            if ($createdState) {
                $this->removeById($em, CmsModuleState::class, 'integrations');
            } else {
                $managedState = $em->find(CmsModuleState::class, 'integrations');
                if ($managedState instanceof CmsModuleState) {
                    $managedState->setEnabled($wasEnabled);
                }
            }
            $em->flush();
        }
    }

    private function target(string $capability, string $key, string $name): ExternalConnectorTarget
    {
        return (new ExternalConnectorTarget())
            ->setCapability($capability)
            ->setTargetKey($key)
            ->setProviderKey('synthetic-provider')
            ->setDisplayName($name)
            ->setEnabled(false)
            ->setRequired(false)
            ->setPriority(10)
            ->setConfigurationReference('synthetic.'.$key);
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('connector-health-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Connector health test')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }

    private function removeUser(EntityManagerInterface $em, User $user): void
    {
        $this->removeById($em, User::class, $user->getId());
    }

    private function removeById(EntityManagerInterface $em, string $class, int|string|null $id): void
    {
        if ($id === null) {
            return;
        }

        $managed = $em->find($class, $id);
        if ($managed !== null) {
            $em->remove($managed);
        }
    }
}
