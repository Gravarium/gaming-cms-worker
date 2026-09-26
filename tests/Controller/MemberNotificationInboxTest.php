<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Game;
use App\Entity\Guild;
use App\Entity\MemberNotification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MemberNotificationInboxTest extends WebTestCase
{
    public function testInboxPaginatesFullHistoryForOnlyTheCurrentUser(): void
    {
        $client = static::createClient();
        $user = $this->user($client, 'member');
        $otherUser = $this->user($client, 'other');
        $guild = $this->guild($client);

        for ($number = 1; $number <= 60; ++$number) {
            $this->em($client)->persist($this->notification($user, $guild, sprintf('Notice %02d', $number)));
        }
        $this->em($client)->persist($this->notification($otherUser, $guild, 'Private notice'));
        $this->em($client)->flush();
        $client->loginUser($user);

        $client->request('GET', '/guild-area/notifications');
        self::assertResponseIsSuccessful();
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow, noarchive');
        self::assertStringContainsString('Notice 60', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Notice 36', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Notice 35', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Private notice', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Ältere Benachrichtigungen', (string) $client->getResponse()->getContent());

        $client->request('GET', '/guild-area/notifications?page=2');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Notice 35', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Notice 36', (string) $client->getResponse()->getContent());

        $client->request('GET', '/guild-area/notifications?page=3');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Notice 10', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Notice 01', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Notice 11', (string) $client->getResponse()->getContent());
    }

    public function testGuildPortalLinksToAnEmptyInbox(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, 'empty'));

        $client->request('GET', '/guild-area');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/guild-area/notifications"]');
        self::assertSelectorTextContains('body', 'Du hast noch keine Benachrichtigungen.');

        $client->request('GET', '/guild-area/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Keine Benachrichtigungen');
        self::assertSelectorNotExists('a[href*="page="]');
    }

    public function testInboxRequiresMemberAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/guild-area/notifications');

        self::assertResponseRedirects('/login');
    }

    public function testMalformedAndOutOfRangePageValuesFailClosed(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($client, 'invalid-page'));

        foreach ([
            '/guild-area/notifications?page=0',
            '/guild-area/notifications?page=-1',
            '/guild-area/notifications?page=abc',
            '/guild-area/notifications?page[]=2',
            '/guild-area/notifications?page=1234567890',
            '/guild-area/notifications?page=2',
        ] as $uri) {
            $client->request('GET', $uri);
            self::assertResponseStatusCodeSame(404, $uri);
        }
    }

    public function testReadActionRequiresCsrfAndHidesForeignNotifications(): void
    {
        $client = static::createClient();
        $owner = $this->user($client, 'owner');
        $otherUser = $this->user($client, 'reader');
        $guild = $this->guild($client);
        $notification = $this->notification($owner, $guild, 'Owner-only notice');
        $this->em($client)->persist($notification);
        $this->em($client)->flush();
        $id = $notification->getId();
        self::assertNotNull($id);

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/guild-area/notifications');
        $token = (string) $crawler
            ->filter('form[action="/guild-area/notifications/'.$id.'/read"] input[name="_token"]')
            ->attr('value');

        $client->request('POST', '/guild-area/notifications/'.$id.'/read');
        self::assertResponseStatusCodeSame(403);
        self::assertNull($notification->getReadAt());

        $otherUserId = $otherUser->getId();
        self::assertNotNull($otherUserId);
        $this->em($client)->getConnection()->update(
            'member_notification',
            ['user_id' => $otherUserId],
            ['id' => $id],
        );
        $this->em($client)->refresh($notification);

        $client->request('POST', '/guild-area/notifications/'.$id.'/read', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        self::assertNull($notification->getReadAt());
    }

    public function testReadActionAllowsSafeLocalTargetAndRejectsUnsafeStoredTarget(): void
    {
        $client = static::createClient();
        $user = $this->user($client, 'redirect');
        $guild = $this->guild($client);
        $safe = $this->notification($user, $guild, 'Safe notice', '/guild-area');
        $unsafe = $this->notification($user, $guild, 'Unsafe legacy notice', '/guild-area');
        $this->em($client)->persist($safe);
        $this->em($client)->persist($unsafe);
        $this->em($client)->flush();
        $unsafeId = $unsafe->getId();
        self::assertNotNull($safe->getId());
        self::assertNotNull($unsafeId);

        $this->em($client)->getConnection()->update(
            'member_notification',
            ['link' => 'https://evil.example/'],
            ['id' => $unsafeId],
        );
        $this->em($client)->refresh($unsafe);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/guild-area/notifications');

        $safeId = $safe->getId();
        self::assertNotNull($safeId);
        $safeToken = (string) $crawler
            ->filter('form[action="/guild-area/notifications/'.$safeId.'/read"] input[name="_token"]')
            ->attr('value');
        $unsafeToken = (string) $crawler
            ->filter('form[action="/guild-area/notifications/'.$unsafeId.'/read"] input[name="_token"]')
            ->attr('value');

        $client->request('POST', '/guild-area/notifications/'.$safeId.'/read', ['_token' => $safeToken]);
        self::assertResponseRedirects('/guild-area');

        $client->request('POST', '/guild-area/notifications/'.$unsafeId.'/read', ['_token' => $unsafeToken]);
        self::assertResponseRedirects('/guild-area/notifications');
        self::assertStringNotContainsString('evil.example', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testReadAllRequiresCsrfAndOnlyUpdatesTheCurrentUser(): void
    {
        $client = static::createClient();
        $user = $this->user($client, 'bulk-owner');
        $otherUser = $this->user($client, 'bulk-other');
        $guild = $this->guild($client);
        $first = $this->notification($user, $guild, 'First unread');
        $second = $this->notification($user, $guild, 'Second unread');
        $alreadyRead = $this->notification($user, $guild, 'Already read', null, true);
        $foreign = $this->notification($otherUser, $guild, 'Foreign unread');
        foreach ([$first, $second, $alreadyRead, $foreign] as $notification) {
            $this->em($client)->persist($notification);
        }
        $this->em($client)->flush();
        $firstId = $first->getId();
        $secondId = $second->getId();
        $alreadyReadId = $alreadyRead->getId();
        $foreignId = $foreign->getId();
        self::assertNotNull($firstId);
        self::assertNotNull($secondId);
        self::assertNotNull($alreadyReadId);
        self::assertNotNull($foreignId);
        $client->loginUser($user);
        $crawler = $client->request('GET', '/guild-area/notifications');
        $readAllToken = (string) $crawler
            ->filter('form[action="/guild-area/notifications/mark-all-read"] input[name="_token"]')
            ->attr('value');

        $client->request('POST', '/guild-area/notifications/mark-all-read');
        self::assertResponseStatusCodeSame(403);
        self::assertNull($first->getReadAt());

        $client->request('POST', '/guild-area/notifications/mark-all-read', ['_token' => $readAllToken]);
        self::assertResponseRedirects('/guild-area/notifications');

        $entityManager = $this->em($client);
        $firstStored = $entityManager->getRepository(MemberNotification::class)->find($firstId);
        $secondStored = $entityManager->getRepository(MemberNotification::class)->find($secondId);
        $alreadyReadStored = $entityManager->getRepository(MemberNotification::class)->find($alreadyReadId);
        $foreignStored = $entityManager->getRepository(MemberNotification::class)->find($foreignId);
        self::assertInstanceOf(MemberNotification::class, $firstStored);
        self::assertInstanceOf(MemberNotification::class, $secondStored);
        self::assertInstanceOf(MemberNotification::class, $alreadyReadStored);
        self::assertInstanceOf(MemberNotification::class, $foreignStored);
        self::assertNotNull($firstStored->getReadAt());
        self::assertNotNull($secondStored->getReadAt());
        self::assertNotNull($alreadyReadStored->getReadAt());
        self::assertNull($foreignStored->getReadAt());
    }

    private function user(KernelBrowser $client, string $prefix): User
    {
        $user = (new User())
            ->setEmail($prefix.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName($prefix)
            ->verifyEmail();
        $this->em($client)->persist($user);
        $this->em($client)->flush();

        return $user;
    }

    private function guild(KernelBrowser $client): Guild
    {
        $suffix = bin2hex(random_bytes(5));
        $game = (new Game())->setName('Inbox game '.$suffix)->setSlug('inbox-game-'.$suffix);
        $guild = (new Guild())
            ->setGame($game)
            ->setName('Inbox guild '.$suffix)
            ->setSlug('inbox-guild-'.$suffix)
            ->setServerName('Inbox server')
            ->setDescription('Test guild for private member notifications.');

        $this->em($client)->persist($game);
        $this->em($client)->persist($guild);
        $this->em($client)->flush();

        return $guild;
    }

    private function notification(
        User $user,
        Guild $guild,
        string $title,
        ?string $link = null,
        bool $read = false,
    ): MemberNotification {
        $notification = (new MemberNotification())
            ->setUser($user)
            ->setGuild($guild)
            ->setTitle($title)
            ->setMessage('Message for '.$title);
        if ($link !== null) {
            $notification->setLink($link);
        }
        if ($read) {
            $notification->markRead();
        }

        return $notification;
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
