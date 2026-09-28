<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminAuditLogAccessSecurityTest extends WebTestCase
{
    public function testContentManagerCannotSeeOrOpenAuditLog(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'content-only', [CmsPermission::CONTENT]);
        $client->loginUser($user);

        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/admin/audit-log"]');

        $client->request('GET', '/admin/audit-log');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAuditPermissionShowsDashboardLinkAndAuditEntries(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'audit-viewer', [CmsPermission::AUDIT]);
        $marker = 'audit-access-test-'.bin2hex(random_bytes(6));
        $entry = (new AuditLog())
            ->setActor($user)
            ->setAction('security.audit.access_test')
            ->setSubjectType('security_boundary_test')
            ->setSummary($marker);
        $client->getContainer()->get(EntityManagerInterface::class)->persist($entry);
        $client->getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($user);

        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/audit-log"]');

        $client->request('GET', '/admin/audit-log');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', $marker);
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('audit-access-'.$label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Audit access '.$label)
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions)
            ->verifyEmail();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
