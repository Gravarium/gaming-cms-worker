<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MenuItem;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AdminMenuOrderingTest extends WebTestCase
{
    /** @var list<int> */
    private array $menuItemIds = [];

    private ?KernelBrowser $testClient = null;

    protected function tearDown(): void
    {
        try {
            if ($this->testClient !== null && $this->menuItemIds !== []) {
                $entityManager = $this->testClient->getContainer()->get(EntityManagerInterface::class);
                foreach ($this->menuItemIds as $id) {
                    $item = $entityManager->find(MenuItem::class, $id);
                    if ($item instanceof MenuItem) {
                        $entityManager->remove($item);
                    }
                }
                $entityManager->flush();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testManagerCanOpenTheLinkedReorderScreenAndMoveAnItem(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $client->loginUser($fixture['manager']);

        $client->request('GET', '/admin/menu/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/menu/order"]');

        $crawler = $client->request('GET', '/admin/menu/order');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('body', 'Ausgeblendet');

        $lastId = $this->id($fixture['last']);
        $action = '/admin/menu/order/'.$lastId.'/up';
        $token = (string) $crawler->filter('form[action="'.$action.'"] input[name="_token"]')->attr('value');
        self::assertNotSame('', $token);

        $client->request('POST', $action, ['_token' => $token]);
        self::assertResponseRedirects('/admin/menu/order');

        $entityManager = $this->em($client);
        $entityManager->clear();
        $ordered = $entityManager->getRepository(MenuItem::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
        self::assertSame(
            [$this->id($fixture['first']), $lastId, $this->id($fixture['hidden'])],
            array_map(static fn (MenuItem $item): ?int => $item->getId(), $ordered),
        );
        self::assertSame([0, 1, 2], array_map(static fn (MenuItem $item): int => $item->getPosition(), $ordered));

        $first = $entityManager->find(MenuItem::class, $this->id($fixture['first']));
        $hidden = $entityManager->find(MenuItem::class, $this->id($fixture['hidden']));
        $last = $entityManager->find(MenuItem::class, $lastId);
        self::assertInstanceOf(MenuItem::class, $first);
        self::assertInstanceOf(MenuItem::class, $hidden);
        self::assertInstanceOf(MenuItem::class, $last);
        self::assertSame('First item', $first->getLabel());
        self::assertSame('https://example.test/first', $first->getUrl());
        self::assertTrue($first->isEnabled());
        self::assertTrue($first->isOpenNewWindow());
        self::assertSame('Hidden item', $hidden->getLabel());
        self::assertSame('https://example.test/hidden', $hidden->getUrl());
        self::assertFalse($hidden->isEnabled());
        self::assertSame('Last item', $last->getLabel());
        self::assertSame('https://example.test/last', $last->getUrl());
        self::assertTrue($last->isEnabled());
        self::assertFalse($last->isOpenNewWindow());
    }

    public function testMoveRequiresManagerPermission(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client, []);
        $client->request('GET', '/admin/menu/order');
        self::assertResponseStatusCodeSame(302);

        $client->loginUser($fixture['manager']);
        $client->request('GET', '/admin/menu/order');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/admin/menu/order/'.$this->id($fixture['last']).'/up', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(
            [$this->id($fixture['first']), $this->id($fixture['hidden']), $this->id($fixture['last'])],
            array_map(static fn (MenuItem $item): ?int => $item->getId(), $this->ordered($client)),
        );
    }

    public function testInvalidCsrfAndUnknownItemsDoNotChangeTheOrder(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $client->loginUser($fixture['manager']);
        $before = array_map(static fn (MenuItem $item): ?int => $item->getId(), $this->ordered($client));

        $lastId = $this->id($fixture['last']);
        $client->request('POST', '/admin/menu/order/'.$lastId.'/up', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, array_map(static fn (MenuItem $item): ?int => $item->getId(), $this->ordered($client)));

        $unknownId = 2147483000;
        $token = $this->csrfToken($client, 'menu-order-'.$unknownId.'-up');
        $client->request('POST', '/admin/menu/order/'.$unknownId.'/up', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($before, array_map(static fn (MenuItem $item): ?int => $item->getId(), $this->ordered($client)));
    }

    public function testFirstAndLastItemMovesAreSafeNoOps(): void
    {
        $client = static::createClient();
        $fixture = $this->fixture($client);
        $client->loginUser($fixture['manager']);
        $before = array_map(static fn (MenuItem $item): ?int => $item->getId(), $this->ordered($client));

        $crawler = $client->request('GET', '/admin/menu/order');
        $firstId = $this->id($fixture['first']);
        $firstAction = '/admin/menu/order/'.$firstId.'/up';
        $firstToken = (string) $crawler->filter('form[action="'.$firstAction.'"] input[name="_token"]')->attr('value');
        $client->request('POST', $firstAction, ['_token' => $firstToken]);
        self::assertResponseRedirects('/admin/menu/order');

        $crawler = $client->request('GET', '/admin/menu/order');
        $lastId = $this->id($fixture['last']);
        $lastAction = '/admin/menu/order/'.$lastId.'/down';
        $lastToken = (string) $crawler->filter('form[action="'.$lastAction.'"] input[name="_token"]')->attr('value');
        $client->request('POST', $lastAction, ['_token' => $lastToken]);
        self::assertResponseRedirects('/admin/menu/order');
        self::assertSame($before, array_map(static fn (MenuItem $item): ?int => $item->getId(), $this->ordered($client)));
    }

    /**
     * @param list<string> $permissions
     * @return array{manager: User, first: MenuItem, hidden: MenuItem, last: MenuItem}
     */
    private function fixture(KernelBrowser $client, array $permissions = [CmsPermission::CONTENT]): array
    {
        $this->testClient = $client;
        $suffix = bin2hex(random_bytes(5));
        $entityManager = $this->em($client);
        $manager = (new User())
            ->setEmail('menu-order-'.$suffix.'@example.test')
            ->setDisplayName('Menu manager')
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions);
        $first = (new MenuItem())
            ->setLabel('First item')
            ->setUrl('https://example.test/first')
            ->setPosition(8)
            ->setEnabled(true)
            ->setOpenNewWindow(true);
        $hidden = (new MenuItem())
            ->setLabel('Hidden item')
            ->setUrl('https://example.test/hidden')
            ->setPosition(8)
            ->setEnabled(false);
        $last = (new MenuItem())
            ->setLabel('Last item')
            ->setUrl('https://example.test/last')
            ->setPosition(60)
            ->setEnabled(true);

        foreach ([$manager, $first, $hidden, $last] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        foreach ([$first, $hidden, $last] as $item) {
            $id = $item->getId();
            if ($id !== null) {
                $this->menuItemIds[] = $id;
            }
        }

        self::assertNotNull($first->getId());
        self::assertNotNull($hidden->getId());
        self::assertNotNull($last->getId());

        return ['manager' => $manager, 'first' => $first, 'hidden' => $hidden, 'last' => $last];
    }

    /**
     * @return list<MenuItem>
     */
    private function ordered(KernelBrowser $client): array
    {
        /** @var list<MenuItem> $items */
        $items = $this->em($client)->getRepository(MenuItem::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']);

        return $items;
    }

    private function id(MenuItem $item): int
    {
        $id = $item->getId();
        self::assertNotNull($id);

        return $id;
    }

    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $browserRequest = $client->getRequest();
        self::assertTrue($browserRequest->hasSession());
        $session = $browserRequest->getSession();
        self::assertInstanceOf(SessionInterface::class, $session);
        $session->start();

        $request = Request::create('/');
        $request->setSession($session);
        $requestStack = $container->get(RequestStack::class);
        $requestStack->push($request);

        try {
            $tokenManager = $container->get(CsrfTokenManagerInterface::class);
            return $tokenManager->getToken($tokenId)->getValue();
        } finally {
            $requestStack->pop();
            $session->save();
        }
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
