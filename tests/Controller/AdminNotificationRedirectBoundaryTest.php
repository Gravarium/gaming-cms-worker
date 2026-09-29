<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminNotification;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminNotificationRedirectBoundaryTest extends WebTestCase
{
    public function testUnsafeStoredLinkFallsBackToNotificationIndex(): void
    {
        $client = static::createClient();
        $notificationId = $this->createNotification($client, '/admin/dashboard');
        $client->loginUser($this->user($client));

        $this->em($client)->getConnection()->executeStatement(
            'UPDATE admin_notification SET link = :link WHERE id = :id',
            ['link' => 'https://outside.example.test/redirect', 'id' => $notificationId],
        );
        $this->em($client)->clear();

        $client->request('POST', '/admin/notifications/'.$notificationId.'/read', [
            '_token' => $this->csrfToken($client, $notificationId),
        ]);

        self::assertResponseRedirects('/admin/notifications');
        $this->em($client)->clear();
        $stored = $this->em($client)->find(AdminNotification::class, $notificationId);
        self::assertInstanceOf(AdminNotification::class, $stored);
        self::assertTrue($stored->isRead());
    }

    public function testSafeStoredLinkStillRedirectsToItsLocalTarget(): void
    {
        $client = static::createClient();
        $notificationId = $this->createNotification($client, '/admin/gaming/applications');
        $client->loginUser($this->user($client));

        $client->request('POST', '/admin/notifications/'.$notificationId.'/read', [
            '_token' => $this->csrfToken($client, $notificationId),
        ]);

        self::assertResponseRedirects('/admin/gaming/applications');
    }

    private function createNotification(KernelBrowser $client, string $link): int
    {
        $notification = (new AdminNotification())
            ->setType('system')
            ->setTitle('Redirect boundary test')
            ->setMessage('Notification test')
            ->setLink($link);
        $this->em($client)->persist($notification);
        $this->em($client)->flush();

        $id = $notification->getId();
        if ($id === null) {
            throw new \LogicException('Could not persist notification fixture.');
        }

        return $id;
    }

    private function user(KernelBrowser $client): User
    {
        $user = (new User())
            ->setEmail('notification-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Notification test')
            ->setPassword('unused-test-hash')
            ->setPermissions([CmsPermission::ACCESS])
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function csrfToken(KernelBrowser $client, int $notificationId): string
    {
        $crawler = $client->request('GET', '/admin/notifications');
        self::assertResponseIsSuccessful();

        return (string) $crawler
            ->filter('form[action="/admin/notifications/'.$notificationId.'/read"] input[name="_token"]')
            ->attr('value');
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
