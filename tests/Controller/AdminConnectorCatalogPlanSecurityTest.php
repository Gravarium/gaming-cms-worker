<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\ExternalConnectorTarget;
use App\Entity\User;
use App\ExternalConnector\ConnectorProviderCatalog;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AdminConnectorCatalogPlanSecurityTest extends WebTestCase
{
    public function testCatalogueAndPlanningRequireConnectorPermission(): void
    {
        $client = static::createClient();
        $provider = $this->firstProvider($client);
        $user = $this->createUser($client, 'catalog-denied', [CmsPermission::CONTENT]);
        $cleanupProvider = null;

        try {
            self::assertNull($this->findTarget($client, $provider));
            $cleanupProvider = $provider;
            $client->loginUser($user);

            $client->request('GET', '/admin/connectors/catalog');
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/admin/connectors/catalog/plan', [
                'providers' => [$provider['choice']],
            ]);
            self::assertResponseStatusCodeSame(403);

            self::assertNull($this->findTarget($client, $provider));
            self::assertSame([], $this->auditEntries($client, $user));
        } finally {
            $this->cleanup($client, $user, $cleanupProvider);
        }
    }

    public function testMissingAndInvalidCsrfDoNotCreateTargetsOrAuditEntries(): void
    {
        $client = static::createClient();
        $provider = $this->firstProvider($client);
        $user = $this->createUser($client, 'catalog-csrf', [CmsPermission::CONNECTORS]);
        $cleanupProvider = null;

        try {
            self::assertNull($this->findTarget($client, $provider));
            $cleanupProvider = $provider;
            $client->loginUser($user);

            $crawler = $client->request('GET', '/admin/connectors/catalog');
            self::assertResponseIsSuccessful();
            $token = (string) $crawler
                ->filter('form[action="/admin/connectors/catalog/plan"] input[name="_token"]')
                ->attr('value');
            self::assertNotSame('', $token);
            self::assertCount(
                1,
                $crawler->filter(sprintf('input[name="providers[]"][value="%s"]', $provider['choice'])),
            );

            foreach ([[], ['_token' => 'invalid']] as $csrfParameters) {
                $client->request('POST', '/admin/connectors/catalog/plan', [
                    'providers' => [$provider['choice']],
                    ...$csrfParameters,
                ]);
                self::assertResponseStatusCodeSame(403);
                self::assertNull($this->findTarget($client, $provider));
                self::assertSame([], $this->auditEntries($client, $user));
            }
        } finally {
            $this->cleanup($client, $user, $cleanupProvider);
        }
    }

    public function testRenderedTokenCreatesOnlyDisabledOptionalCatalogueTarget(): void
    {
        $client = static::createClient();
        $provider = $this->firstProvider($client);
        $user = $this->createUser($client, 'catalog-plan', [CmsPermission::CONNECTORS]);
        $cleanupProvider = null;

        try {
            self::assertNull($this->findTarget($client, $provider));
            $cleanupProvider = $provider;
            $client->loginUser($user);

            $crawler = $client->request('GET', '/admin/connectors/catalog');
            self::assertResponseIsSuccessful();
            $token = (string) $crawler
                ->filter('form[action="/admin/connectors/catalog/plan"] input[name="_token"]')
                ->attr('value');
            self::assertNotSame('', $token);
            self::assertCount(
                1,
                $crawler->filter(sprintf('input[name="providers[]"][value="%s"]', $provider['choice'])),
            );

            $client->request('POST', '/admin/connectors/catalog/plan', [
                '_token' => $token,
                'providers' => [$provider['choice']],
            ]);

            self::assertResponseRedirects('/admin/connectors');

            $target = $this->findTarget($client, $provider);
            self::assertInstanceOf(ExternalConnectorTarget::class, $target);
            self::assertSame($provider['capability'], $target->getCapability());
            self::assertSame($provider['target_key'], $target->getTargetKey());
            self::assertSame($provider['provider_key'], $target->getProviderKey());
            self::assertFalse($target->isEnabled());
            self::assertFalse($target->isRequired());
            self::assertSame($provider['configuration_reference'], $target->getConfigurationReference());
            self::assertMatchesRegularExpression('/^[a-z0-9][a-z0-9_.-]*$/', (string) $target->getConfigurationReference());

            $auditEntries = $this->auditEntries($client, $user);
            self::assertCount(1, $auditEntries);
            self::assertSame('connector.catalog.plan', $auditEntries[0]->getAction());
        } finally {
            $this->cleanup($client, $user, $cleanupProvider);
        }
    }

    /**
     * @return array{
     *     choice: string,
     *     capability: string,
     *     provider_key: string,
     *     target_key: string,
     *     configuration_reference: string
     * }
     */
    private function firstProvider(KernelBrowser $client): array
    {
        $catalog = $client->getContainer()->get(ConnectorProviderCatalog::class);
        self::assertInstanceOf(ConnectorProviderCatalog::class, $catalog);

        foreach ($catalog->indexed() as $choice => $provider) {
            return [
                'choice' => $choice,
                'capability' => $provider['capability'],
                'provider_key' => $provider['key'],
                'target_key' => $provider['capability'].'-'.$provider['key'],
                'configuration_reference' => $provider['capability'].'.'.$provider['key'],
            ];
        }

        throw new LogicException('The connector provider catalogue must contain at least one provider.');
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('connector-catalog-'.$label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Connector catalogue '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();

        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    /**
     * @param array{
     *     choice: string,
     *     capability: string,
     *     provider_key: string,
     *     target_key: string,
     *     configuration_reference: string
     * } $provider
     */
    private function findTarget(KernelBrowser $client, array $provider): ?ExternalConnectorTarget
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $target = $entityManager->getRepository(ExternalConnectorTarget::class)->findOneBy([
            'capability' => $provider['capability'],
            'targetKey' => $provider['target_key'],
        ]);

        return $target instanceof ExternalConnectorTarget ? $target : null;
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
     * @param array{
     *     choice: string,
     *     capability: string,
     *     provider_key: string,
     *     target_key: string,
     *     configuration_reference: string
     * }|null $provider
     */
    private function cleanup(KernelBrowser $client, User $user, ?array $provider): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        if ($provider !== null) {
            $target = $entityManager->getRepository(ExternalConnectorTarget::class)->findOneBy([
                'capability' => $provider['capability'],
                'targetKey' => $provider['target_key'],
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
                    $entityManager->remove($entry);
                }
                $entityManager->remove($storedUser);
            }
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
