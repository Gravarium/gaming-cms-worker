<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CmsModuleState;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoProviderAuditDashboardEntryTest extends WebTestCase
{
    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
            $db->executeStatement("DELETE FROM cms_user WHERE email LIKE 'video-provider-audit-dashboard-test-%'");
            $db->executeStatement('UPDATE cms_module_state SET enabled = ? WHERE module_key = ?', [true, 'video']);
        }

        parent::tearDown();
    }

    public function testAuditDashboardCardRequiresVideoPermissionAndEnabledModule(): void
    {
        $client = static::createClient();
        $this->setVideoModule($client, true);
        $client->loginUser($this->user($client, true));
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.video-provider-audit-link');

        $state = $client->getContainer()->get(EntityManagerInterface::class)->find(CmsModuleState::class, 'video');
        self::assertInstanceOf(CmsModuleState::class, $state);
        $state->setEnabled(false);
        $client->getContainer()->get(EntityManagerInterface::class)->flush();
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.video-provider-audit-link');

        $client->getCookieJar()->clear();
        $this->setVideoModule($client, true);
        $client->loginUser($this->user($client, false));
        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.video-provider-audit-link');
    }

    private function setVideoModule(KernelBrowser $client, bool $enabled): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $state = $em->find(CmsModuleState::class, 'video');
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())->setModuleKey('video')->updateVersion('test');
            $em->persist($state);
        }
        $state->setEnabled($enabled);
        $em->flush();
    }

    private function user(KernelBrowser $client, bool $manager): User
    {
        $user = (new User())
            ->setEmail('video-provider-audit-dashboard-test-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Video audit dashboard test')
            ->setPassword('unused-test-hash')
            ->verifyEmail()
            ->setPermissions($manager
                ? [CmsPermission::ACCESS, CmsPermission::VIDEO]
                : [CmsPermission::ACCESS, CmsPermission::CONTENT]);
        $client->getContainer()->get(EntityManagerInterface::class)->persist($user);
        $client->getContainer()->get(EntityManagerInterface::class)->flush();

        return $user;
    }
}
