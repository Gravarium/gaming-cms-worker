<?php

declare(strict_types=1);

namespace App\\Tests\\Controller;

use App\\Entity\\User;
use App\\Security\\CmsPermission;
use Doctrine\\ORM\\EntityManagerInterface;
use Symfony\\Bundle\\FrameworkBundle\\KernelBrowser;
use Symfony\\Bundle\\FrameworkBundle\\Test\\WebTestCase;

final class AdminQueueRetrySecurityTest extends WebTestCase
{
    public function testUserWithoutSettingsPermissionCannotRetryFailedMessages(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'queue-limited', [CmsPermission::CONTENT]);
        $client->loginUser($user);

        $client->request('POST', '/admin/queue/retry-all', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/queue/42/retry', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testSettingsManagerCannotRetryAllWithoutValidCsrfToken(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser($client, 'queue-retry-all', [CmsPermission::SETTINGS]));

        $client->request('POST', '/admin/queue/retry-all');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/queue/retry-all', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testSettingsManagerCannotRetrySingleMessageWithoutValidCsrfToken(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser($client, 'queue-retry-one', [CmsPermission::SETTINGS]));

        $client->request('POST', '/admin/queue/42/retry');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/queue/42/retry', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail($label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Queue security '.$label)
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
