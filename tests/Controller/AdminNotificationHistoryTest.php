<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AdminNotification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminNotificationHistoryTest extends WebTestCase
{
    public function testHistoryLinkSearchAndReadStateFilterRespectNotificationStatus(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $readTitleMatch = $this->notification('needle in read title', 'different message');
        $readTitleMatch->markRead();
        $unreadMessageMatch = $this->notification('Other title', 'needle in unread message');
        $unreadTypeMatch = $this->notification('Type only match', 'Different text', 'needle-type');
        $unreadNonMatch = $this->notification('No matching text', 'Other message');
        foreach ([$readTitleMatch, $unreadMessageMatch, $unreadTypeMatch, $unreadNonMatch] as $notification) {
            $this->em($client)->persist($notification);
        }
        $this->em($client)->flush();

        $client->request('GET', '/admin/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/notifications/history"]');

        $crawler = $client->request('GET', '/admin/notifications/history?q=needle&state=unread');
        self::assertResponseIsSuccessful();
        $cacheControl = $client->getResponse()->headers->get('Cache-Control') ?? '';
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
        $body = $crawler->filter('body')->text();
        self::assertStringContainsString('needle in unread message', $body);
        self::assertStringContainsString('needle-type', $body);
        self::assertStringNotContainsString('needle in read title', $body);
        self::assertStringNotContainsString('No matching text', $body);
    }

    public function testHistoryUsesTheExistingCsrfProtectedReadAction(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $notification = $this->notification('Unread item', 'A notification to read');
        $this->em($client)->persist($notification);
        $this->em($client)->flush();
        $id = $notification->getId();
        self::assertNotNull($id);

        $client->request('POST', '/admin/notifications/'.$id.'/read');
        self::assertResponseStatusCodeSame(403);
        $this->em($client)->clear();
        $stored = $this->em($client)->find(AdminNotification::class, $id);
        self::assertInstanceOf(AdminNotification::class, $stored);
        self::assertFalse($stored->isRead());

        $crawler = $client->request('GET', '/admin/notifications/history?state=unread');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action="/admin/notifications/'.$id.'/read"]')->form();
        $client->submit($form);
        self::assertResponseRedirects('/admin/notifications');

        $this->em($client)->clear();
        $stored = $this->em($client)->find(AdminNotification::class, $id);
        self::assertInstanceOf(AdminNotification::class, $stored);
        self::assertTrue($stored->isRead());
    }

    public function testHistoryPaginationRetainsFiltersAndHasNoDuplicateResults(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        for ($index = 1; $index <= 27; ++$index) {
            $this->em($client)->persist($this->notification('History item '.$index, 'pagination-marker'));
        }
        $this->em($client)->flush();

        $crawler = $client->request('GET', '/admin/notifications/history?q=pagination-marker&state=all');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '27 Benachrichtigungen');
        $pageOneTitles = $crawler->filter('.application-card h2')->each(static fn (Crawler $node): string => trim($node->text()));
        self::assertCount(25, $pageOneTitles);

        $nextLink = $crawler->filter('nav.pagination a')->last()->attr('href');
        self::assertIsString($nextLink);
        $queryString = parse_url($nextLink, PHP_URL_QUERY);
        self::assertIsString($queryString);
        parse_str($queryString, $queryParameters);
        self::assertSame('pagination-marker', $queryParameters['q'] ?? null);
        self::assertSame('all', $queryParameters['state'] ?? null);
        self::assertSame('2', $queryParameters['page'] ?? null);

        $crawler = $client->request('GET', $nextLink);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Seite 2 von 2');
        $pageTwoTitles = $crawler->filter('.application-card h2')->each(static fn (Crawler $node): string => trim($node->text()));
        self::assertCount(2, $pageTwoTitles);
        self::assertCount(27, array_unique([...$pageOneTitles, ...$pageTwoTitles]));
    }

    #[DataProvider('invalidPageValues')]
    public function testHistoryRejectsMalformedAndOutOfRangePages(string $queryString): void
    {
        $client = static::createClient();
        $client->loginUser($this->createAdmin($client));

        $client->request('GET', '/admin/notifications/history?'.$queryString);

        self::assertResponseStatusCodeSame(404);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPageValues(): iterable
    {
        yield 'zero' => ['page=0'];
        yield 'negative' => ['page=-1'];
        yield 'non-numeric' => ['page=abc'];
        yield 'array' => ['page%5B%5D=2'];
        yield 'over the page bound' => ['page=10001'];
        yield 'past the last page' => ['q=__wcp409-no-match-42d9&page=2'];
    }

    public function testHistoryRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/notifications/history');

        self::assertResponseRedirects('/login');
    }

    public function testHistoryRequiresCmsAccess(): void
    {
        $client = static::createClient();
        $member = (new User())
            ->setEmail('notification-member-'.bin2hex(random_bytes(8)).'@example.test')
            ->setDisplayName('Notification member')
            ->setPassword('unused-test-hash');
        $this->em($client)->persist($member);
        $this->em($client)->flush();
        $client->loginUser($member);

        $client->request('GET', '/admin/notifications/history');
        self::assertResponseStatusCodeSame(403);
    }

    private function createAdmin(KernelBrowser $client): User
    {
        $admin = (new User())
            ->setEmail('notification-admin-'.bin2hex(random_bytes(8)).'@example.test')
            ->setDisplayName('Notification administrator')
            ->setPassword('unused-test-hash')
            ->setAdmin(true);
        $this->em($client)->persist($admin);
        $this->em($client)->flush();

        return $admin;
    }

    private function notification(string $title, string $message, string $type = 'system'): AdminNotification
    {
        return (new AdminNotification())->setType($type)->setTitle($title)->setMessage($message);
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
