<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MenuItem;
use App\Entity\User;
use App\Repository\MenuItemRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class MenuItemUrlBoundaryTest extends WebTestCase
{
    public function testMenuUrlAcceptsTheColumnBoundaryAndShowsErrorsWithoutChangingStoredUrl(): void
    {
        $client = static::createClient();
        $admin = (new User())
            ->setEmail('menu-url-admin-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Menu URL administrator')
            ->setAdmin(true)
            ->setPassword('unused-test-hash');
        $this->entityManager($client)->persist($admin);
        $this->entityManager($client)->flush();
        $client->loginUser($admin);

        $items = $client->getContainer()->get(MenuItemRepository::class);
        $initialCount = $items->count([]);
        $prefix = 'https://example.invalid/';
        $validUrl = $prefix.str_repeat('x', 500 - mb_strlen($prefix, 'UTF-8'));
        self::assertSame(500, mb_strlen($validUrl, 'UTF-8'));

        $crawler = $client->request('GET', '/admin/menu/new');
        self::assertSame('500', $crawler->filter('input[name="menu_item[url]"]')->attr('maxlength'));
        $form = $crawler->selectButton('Speichern')->form();
        $form['menu_item[label]'] = 'Boundary menu link';
        $form['menu_item[url]'] = $validUrl;
        $client->submit($form);

        self::assertResponseRedirects('/admin/menu');
        $stored = $items->findOneBy(['url' => $validUrl]);
        self::assertInstanceOf(MenuItem::class, $stored);
        $id = $stored->getId();
        self::assertNotNull($id);
        self::assertSame($initialCount + 1, $items->count([]));

        $tooLongUrl = $validUrl.'x';
        $this->submitEdit($client, $id, $tooLongUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'zu lang oder enthält ungültige Daten');
        self::assertSame($validUrl, $this->storedUrl($client, $id));
        self::assertSame($initialCount + 1, $items->count([]));

        $tooManyBytesUrl = $prefix.str_repeat('🛡', 500);
        self::assertGreaterThan(2000, strlen($tooManyBytesUrl));
        $this->submitEdit($client, $id, $tooManyBytesUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'zu lang oder enthält ungültige Daten');
        self::assertSame($validUrl, $this->storedUrl($client, $id));
        self::assertSame($initialCount + 1, $items->count([]));
    }

    private function submitEdit(KernelBrowser $client, int $id, string $url): Crawler
    {
        $crawler = $client->request('GET', '/admin/menu/'.$id.'/edit');
        $form = $crawler->selectButton('Speichern')->form();
        $form['menu_item[url]'] = $url;

        return $client->submit($form);
    }

    private function storedUrl(KernelBrowser $client, int $id): mixed
    {
        return $client->getContainer()->get(Connection::class)->fetchOne(
            'SELECT url FROM menu_item WHERE id = ?',
            [$id],
        );
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
