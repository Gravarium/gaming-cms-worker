<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminNotification;
use App\Entity\User;
use App\Repository\AdminNotificationRepository;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminNotificationSecurityTest extends WebTestCase
{
    public function testUserWithoutCmsAccessCannotListOrAcknowledgeNotifications(): void
    {
        $client = static::createClient();
        $firstId = $this->createNotification($client, 'restricted-first');
        $secondId = $this->createNotification($client, 'restricted-second');
        $client->loginUser($this->createUser($client, 'restricted', []));

        $client->request('GET', '/admin/notifications');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/notifications/'.$firstId.'/read', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/notifications/read-all', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $this->assertUnread($client, [$firstId, $secondId]);
    }

    public function testMissingOrInvalidCsrfCannotMarkOneNotificationRead(): void
    {
        $client = static::createClient();
        $notificationId = $this->createNotification($client, 'single-csrf');
        $client->loginUser($this->createUser($client, 'single-csrf', [CmsPermission::ACCESS]));

        $client->request('POST', '/admin/notifications/'.$notificationId.'/read');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/notifications/'.$notificationId.'/read', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $this->assertUnread($client, [$notificationId]);
    }

    public function testMissingOrInvalidCsrfCannotMarkAllNotificationsRead(): void
    {
        $client = static::createClient();
        $firstId = $this->createNotification($client, 'all-csrf-first');
        $secondId = $this->createNotification($client, 'all-csrf-second');
        $client->loginUser($this->createUser($client, 'all-csrf', [CmsPermission::ACCESS]));

        $client->request('POST', '/admin/notifications/read-all');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/notifications/read-all', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $this->assertUnread($client, [$firstId, $secondId]);
    }

    public function testRenderedSingleNotificationTokenMarksOnlyItsTargetRead(): void
    {
        $client = static::createClient();
        $selectedId = $this->createNotification($client, 'valid-token-selected');
        $untouchedId = $this->createNotification($client, 'valid-token-untouched');
        $client->loginUser($this->createUser($client, 'valid-token', [CmsPermission::ACCESS]));

        $crawler = $client->request('GET', '/admin/notifications');
        self::assertResponseIsSuccessful();
        $token = (string) $crawler
            ->filter('form[action="/admin/notifications/'.$selectedId.'/read"] input[name="_token"]')
            ->attr('value');

        self::assertNotSame('', $token);
        $client->request('POST', '/admin/notifications/'.$selectedId.'/read', ['_token' => $token]);
        self::assertResponseRedirects('/admin/notifications');

        $this->entityManager($client)->clear();
        $selected = $this->notifications($client)->find($selectedId);
        $untouched = $this->notifications($client)->find($untouchedId);
        self::assertInstanceOf(AdminNotification::class, $selected);
        self::assertInstanceOf(AdminNotification::class, $untouched);
        self::assertNotNull($selected->getReadAt());
        self::assertNull($untouched->getReadAt());
    }

    private function createNotification(KernelBrowser $client, string $label): int
    {
        $notification = (new AdminNotification())
            ->setType('security-test')
            ->setTitle('Notification '.$label)
            ->setMessage('Synthetic notification fixture '.$label);
        $this->entityManager($client)->persist($notification);
        $this->entityManager($client)->flush();

        $id = $notification->getId();
        self::assertNotNull($id);

        return $id;
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Notification security '.$label)
            ->setPermissions($permissions)
            ->setPassword('unused-test-hash')
            ->verifyEmail();
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    /** @param list<int> $ids */
    private function assertUnread(KernelBrowser $client, array $ids): void
    {
        $this->entityManager($client)->clear();

        foreach ($ids as $id) {
            $notification = $this->notifications($client)->find($id);
            self::assertInstanceOf(AdminNotification::class, $notification);
            self::assertNull($notification->getReadAt());
        }
    }

    private function notifications(KernelBrowser $client): AdminNotificationRepository
    {
        return $client->getContainer()->get(AdminNotificationRepository::class);
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
