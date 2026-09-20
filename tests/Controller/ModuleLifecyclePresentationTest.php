<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Module\CmsModuleManager;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ModuleLifecyclePresentationTest extends WebTestCase
{
    private const MODULE_KEYS = ['content', 'gaming', 'notifications', 'operations'];

    public function testRouteOwnershipUsesRouteNameBoundaries(): void
    {
        $client = static::createClient();
        $modules = $client->getContainer()->get(CmsModuleManager::class);

        self::assertSame('content', $modules->moduleForRoute('app_news'));
        self::assertSame('content', $modules->moduleForRoute('app_news_index'));
        self::assertSame('video', $modules->moduleForRoute('app_admin_video_edit'));
        self::assertNull($modules->moduleForRoute('app_newsletter_index'));
        self::assertNull($modules->moduleForRoute('app_admin_modules_custom'));
    }

    public function testDisabledDependencyMakesDependentModuleFailClosed(): void
    {
        $client = static::createClient();
        $this->resetModuleStates($client);

        try {
            $this->moduleState($client, 'content', false);
            $this->moduleState($client, 'gaming', true);
            $modules = $client->getContainer()->get(CmsModuleManager::class);

            self::assertFalse($modules->isEnabled('gaming'));
            $client->request('GET', '/gaming');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->resetModuleStates($client);
        }
    }

    public function testHomeHidesDisabledContentEntryPoints(): void
    {
        $client = static::createClient();
        $this->resetModuleStates($client);

        try {
            $this->moduleState($client, 'gaming', false);
            $this->moduleState($client, 'content', false);

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextNotContains('body', 'News ansehen');
            self::assertSelectorNotExists('a[href="/news"]');
        } finally {
            $this->resetModuleStates($client);
        }
    }

    public function testDashboardHidesDisabledOperationalModulesAndUnauthorizedAuditLink(): void
    {
        $client = static::createClient();
        $this->resetModuleStates($client);

        try {
            $this->moduleState($client, 'notifications', false);
            $this->moduleState($client, 'operations', false);
            $user = $this->user($client, [CmsPermission::ACCESS, CmsPermission::SETTINGS]);
            $client->loginUser($user);

            $client->request('GET', '/admin');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Modulverwaltung');
            self::assertSelectorTextNotContains('body', 'Benachrichtigungen');
            self::assertSelectorTextNotContains('body', 'Nachrichten-Warteschlange');
            self::assertSelectorTextNotContains('body', 'Änderungsprotokoll');
        } finally {
            $this->resetModuleStates($client);
        }
    }

    public function testDisabledFeatureRouteIsHiddenWhileModuleAdministrationRemainsReachable(): void
    {
        $client = static::createClient();
        $this->resetModuleStates($client);

        try {
            $this->moduleState($client, 'notifications', false);
            $user = $this->user($client, [CmsPermission::ACCESS, CmsPermission::SETTINGS]);
            $client->loginUser($user);

            $client->request('GET', '/admin/notifications');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/admin/modules');
            self::assertResponseIsSuccessful();
        } finally {
            $this->resetModuleStates($client);
        }
    }

    public function testModuleMutationRequiresCsrfAndLeavesStateUntouched(): void
    {
        $client = static::createClient();
        $this->resetModuleStates($client);

        try {
            $user = $this->user($client, [CmsPermission::ACCESS, CmsPermission::SETTINGS]);
            $client->loginUser($user);
            $modules = $client->getContainer()->get(CmsModuleManager::class);
            self::assertTrue($modules->isEnabled('notifications'));

            $client->request('POST', '/admin/modules/notifications/toggle');
            self::assertResponseStatusCodeSame(403);
            self::assertTrue($modules->isEnabled('notifications'));
        } finally {
            $this->resetModuleStates($client);
        }
    }

    public function testDependencyDisableOrderIsEnforced(): void
    {
        $client = static::createClient();
        $this->resetModuleStates($client);

        try {
            $modules = $client->getContainer()->get(CmsModuleManager::class);
            try {
                $modules->setEnabled('content', false);
                self::fail('Content must not be disabled while the gaming module is active.');
            } catch (\DomainException $exception) {
                self::assertStringContainsString('gaming', $exception->getMessage());
            }

            $modules->setEnabled('gaming', false);
            $modules->setEnabled('content', false);
            self::assertFalse($modules->isEnabled('gaming'));
            self::assertFalse($modules->isEnabled('content'));
        } finally {
            $this->resetModuleStates($client);
        }
    }

    /** @param list<string> $permissions */
    private function user(KernelBrowser $client, array $permissions): User
    {
        $user = (new User())
            ->setEmail('module-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Module lifecycle test')
            ->setPermissions($permissions)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function moduleState(KernelBrowser $client, string $key, bool $enabled): void
    {
        $state = (new CmsModuleState())
            ->setModuleKey($key)
            ->updateVersion('1.0.0')
            ->setEnabled($enabled);
        $this->em($client)->persist($state);
        $this->em($client)->flush();
    }

    private function resetModuleStates(KernelBrowser $client): void
    {
        $em = $this->em($client);
        foreach (self::MODULE_KEYS as $key) {
            $state = $em->getRepository(CmsModuleState::class)->find($key);
            if ($state !== null) {
                $em->remove($state);
            }
        }
        $em->flush();
        $em->clear();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
