<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminModuleLifecycleSecurityTest extends WebTestCase
{
    private const MODULE_KEY = 'notifications';

    public function testModuleLifecycleRequiresSettingsPermission(): void
    {
        $client = static::createClient();
        $this->seedModuleState($client, false, false, '1.0.0');
        $user = $this->createUser($client, 'module-lifecycle-denied', [CmsPermission::CONTENT]);

        try {
            $client->loginUser($user);

            $client->request('GET', '/admin/modules');
            self::assertResponseStatusCodeSame(403);

            foreach (['install', 'update', 'remove'] as $action) {
                $client->request('POST', '/admin/modules/'.self::MODULE_KEY.'/'.$action);
                self::assertResponseStatusCodeSame(403);
            }

            $state = $this->moduleState($client);
            self::assertFalse($state->isInstalled());
            self::assertFalse($state->isEnabled());
            self::assertSame('1.0.0', $state->getInstalledVersion());
        } finally {
            $this->cleanup($client, $user);
        }
    }

    public function testLifecycleActionsRejectMissingAndInvalidCsrfWithoutChangingState(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'module-lifecycle-csrf', [CmsPermission::SETTINGS]);

        try {
            $client->loginUser($user);

            $cases = [
                ['action' => 'install', 'installed' => false, 'enabled' => false, 'version' => '1.0.0'],
                ['action' => 'update', 'installed' => true, 'enabled' => true, 'version' => '0.9.0'],
                ['action' => 'remove', 'installed' => true, 'enabled' => true, 'version' => '1.0.0'],
            ];
            $requests = [[], ['_token' => 'invalid']];

            foreach ($cases as $case) {
                $this->seedModuleState($client, $case['installed'], $case['enabled'], $case['version']);

                foreach ($requests as $parameters) {
                    $client->request('POST', '/admin/modules/'.self::MODULE_KEY.'/'.$case['action'], $parameters);
                    self::assertResponseStatusCodeSame(403);
                }

                $state = $this->moduleState($client);
                self::assertSame($case['installed'], $state->isInstalled());
                self::assertSame($case['enabled'], $state->isEnabled());
                self::assertSame($case['version'], $state->getInstalledVersion());
            }
        } finally {
            $this->cleanup($client, $user);
        }
    }

    public function testRenderedCsrfTokensInstallUpdateAndRemoveWhileRetainingModuleState(): void
    {
        $client = static::createClient();
        $this->seedModuleState($client, false, false, '1.0.0');
        $user = $this->createUser($client, 'module-lifecycle-valid', [CmsPermission::SETTINGS]);

        try {
            $client->loginUser($user);

            $installToken = $this->renderedToken($client, 'install');
            $client->request('POST', '/admin/modules/'.self::MODULE_KEY.'/install', ['_token' => $installToken]);
            self::assertResponseRedirects('/admin/modules');

            $state = $this->moduleState($client);
            self::assertTrue($state->isInstalled());
            self::assertFalse($state->isEnabled());
            self::assertSame('1.0.0', $state->getInstalledVersion());

            $state->updateVersion('0.9.0')->setEnabled(true);
            $this->entityManager($client)->flush();

            $updateToken = $this->renderedToken($client, 'update');
            $client->request('POST', '/admin/modules/'.self::MODULE_KEY.'/update', ['_token' => $updateToken]);
            self::assertResponseRedirects('/admin/modules');

            $state = $this->moduleState($client);
            self::assertTrue($state->isInstalled());
            self::assertTrue($state->isEnabled());
            self::assertSame('1.0.0', $state->getInstalledVersion());

            $removeToken = $this->renderedToken($client, 'remove');
            $client->request('POST', '/admin/modules/'.self::MODULE_KEY.'/remove', ['_token' => $removeToken]);
            self::assertResponseRedirects('/admin/modules');

            $state = $this->moduleState($client);
            self::assertFalse($state->isInstalled());
            self::assertFalse($state->isEnabled());
            self::assertSame('1.0.0', $state->getInstalledVersion());

            $client->request('GET', '/admin/modules');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Vorhandene Moduldaten wurden beibehalten.');
        } finally {
            $this->cleanup($client, $user);
        }
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Module lifecycle '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function seedModuleState(KernelBrowser $client, bool $installed, bool $enabled, string $version): void
    {
        $entityManager = $this->entityManager($client);
        $existing = $entityManager->find(CmsModuleState::class, self::MODULE_KEY);
        if ($existing instanceof CmsModuleState) {
            $entityManager->remove($existing);
            $entityManager->flush();
        }

        $state = (new CmsModuleState())
            ->setModuleKey(self::MODULE_KEY)
            ->updateVersion($version);
        if (!$installed) {
            $state->removePackage();
        } elseif (!$enabled) {
            $state->setEnabled(false);
        }

        $entityManager->persist($state);
        $entityManager->flush();
        $entityManager->clear();
    }

    private function moduleState(KernelBrowser $client): CmsModuleState
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $state = $entityManager->find(CmsModuleState::class, self::MODULE_KEY);
        self::assertInstanceOf(CmsModuleState::class, $state);

        return $state;
    }

    private function renderedToken(KernelBrowser $client, string $action): string
    {
        $crawler = $client->request('GET', '/admin/modules');
        self::assertResponseIsSuccessful();

        $token = (string) $crawler
            ->filter('form[action="/admin/modules/'.self::MODULE_KEY.'/'.$action.'"] input[name="_token"]')
            ->attr('value');
        self::assertNotSame('', $token);

        return $token;
    }

    private function cleanup(KernelBrowser $client, User $user): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        $userId = $user->getId();
        if ($userId !== null) {
            $storedUser = $entityManager->find(User::class, $userId);
            if ($storedUser instanceof User) {
                foreach ($entityManager->getRepository(AuditLog::class)->findBy(['actor' => $storedUser]) as $log) {
                    $entityManager->remove($log);
                }
                $entityManager->remove($storedUser);
            }
        }

        $state = $entityManager->find(CmsModuleState::class, self::MODULE_KEY);
        if ($state instanceof CmsModuleState) {
            $entityManager->remove($state);
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
