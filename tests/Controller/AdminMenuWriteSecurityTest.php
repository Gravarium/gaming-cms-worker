<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\MenuItem;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminMenuWriteSecurityTest extends WebTestCase
{
    public function testMenuRoutesRequireContentPermission(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'menu-denied', [CmsPermission::GAMING]);
        $item = $this->createMenuItem($client, 'Denied menu item '.bin2hex(random_bytes(4)));
        $itemId = $item->getId();
        self::assertNotNull($itemId);

        try {
            $client->loginUser($user);

            $client->request('GET', '/admin/menu');
            self::assertResponseStatusCodeSame(403);

            $client->request('GET', '/admin/menu/new');
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/admin/menu/new', [
                'menu_item' => [
                    'label' => 'Unauthorized menu creation',
                    'url' => 'https://example.com/unauthorized',
                ],
            ]);
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/admin/menu/'.$itemId.'/delete');
            self::assertResponseStatusCodeSame(403);

            $stored = $this->findItem($client, $itemId);
            self::assertInstanceOf(MenuItem::class, $stored);
            self::assertSame($item->getLabel(), $stored->getLabel());
        } finally {
            $this->cleanup($client, $user, [$itemId]);
        }
    }

    public function testCreateRequiresFormCsrfAndRenderedTokenCanCreateItem(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'menu-create', [CmsPermission::CONTENT]);
        $label = 'CSRF menu '.bin2hex(random_bytes(5));
        $url = 'https://example.com/'.bin2hex(random_bytes(5));

        try {
            $client->loginUser($user);

            foreach ([
                ['mode' => 'missing', 'suffix' => 'missing'],
                ['mode' => 'invalid', 'suffix' => 'invalid'],
            ] as $case) {
                $forgedLabel = $label.' '.$case['suffix'];
                $values = $this->renderedFormValues($client, $forgedLabel, $url, $case['mode']);

                $client->request('POST', '/admin/menu/new', $values);

                self::assertResponseStatusCodeSame(422);
                self::assertSame([], $this->findItemsByLabel($client, $forgedLabel));
            }

            $values = $this->renderedFormValues($client, $label, $url, 'valid');
            $client->request('POST', '/admin/menu/new', $values);

            self::assertResponseRedirects('/admin/menu');
            $items = $this->findItemsByLabel($client, $label);
            self::assertCount(1, $items);
            self::assertSame($url, $items[0]->getUrl());
        } finally {
            $this->cleanup($client, $user, [], [$label, $label.' missing', $label.' invalid']);
        }
    }

    public function testDeleteRequiresRenderedRouteSpecificCsrfToken(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'menu-delete', [CmsPermission::CONTENT]);
        $item = $this->createMenuItem($client, 'Delete menu '.bin2hex(random_bytes(5)));
        $itemId = $item->getId();
        self::assertNotNull($itemId);

        try {
            $client->loginUser($user);

            foreach ([[], ['_token' => 'invalid']] as $parameters) {
                $client->request('POST', '/admin/menu/'.$itemId.'/delete', $parameters);

                self::assertResponseStatusCodeSame(403);
                $stored = $this->findItem($client, $itemId);
                self::assertInstanceOf(MenuItem::class, $stored);
                self::assertSame($item->getLabel(), $stored->getLabel());
            }

            $crawler = $client->request('GET', '/admin/menu');
            self::assertResponseIsSuccessful();
            $token = (string) $crawler
                ->filter('form[action="/admin/menu/'.$itemId.'/delete"] input[name="_token"]')
                ->attr('value');
            self::assertNotSame('', $token);

            $client->request('POST', '/admin/menu/'.$itemId.'/delete', ['_token' => $token]);

            self::assertResponseRedirects('/admin/menu');
            self::assertNull($this->findItem($client, $itemId));
        } finally {
            $this->cleanup($client, $user, [$itemId]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function renderedFormValues(KernelBrowser $client, string $label, string $url, string $csrfMode): array
    {
        $crawler = $client->request('GET', '/admin/menu/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Speichern')->form();
        $formName = $form->getName();
        self::assertNotSame('', $formName);

        $values = $form->getPhpValues();
        $formValues = $values[$formName] ?? null;
        self::assertIsArray($formValues);
        self::assertArrayHasKey('_token', $formValues);
        self::assertNotSame('', (string) $formValues['_token']);

        $formValues['label'] = $label;
        $formValues['url'] = $url;
        if ($csrfMode === 'missing') {
            unset($formValues['_token']);
        } elseif ($csrfMode === 'invalid') {
            $formValues['_token'] = 'invalid';
        }

        $values[$formName] = $formValues;

        return $values;
    }

    private function createMenuItem(KernelBrowser $client, string $label): MenuItem
    {
        $item = (new MenuItem())
            ->setLabel($label)
            ->setUrl('https://example.com/'.bin2hex(random_bytes(5)))
            ->setPosition(0);
        $this->entityManager($client)->persist($item);
        $this->entityManager($client)->flush();

        return $item;
    }

    /**
     * @return list<MenuItem>
     */
    private function findItemsByLabel(KernelBrowser $client, string $label): array
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $items = $entityManager->getRepository(MenuItem::class)->findBy(['label' => $label]);

        return array_values(array_filter(
            $items,
            static fn (object $item): bool => $item instanceof MenuItem,
        ));
    }

    private function findItem(KernelBrowser $client, int $id): ?MenuItem
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $item = $entityManager->find(MenuItem::class, $id);

        return $item instanceof MenuItem ? $item : null;
    }

    private function cleanup(KernelBrowser $client, User $user, array $itemIds, array $labels = []): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        foreach ($itemIds as $itemId) {
            $item = $entityManager->find(MenuItem::class, $itemId);
            if ($item instanceof MenuItem) {
                $entityManager->remove($item);
            }
        }

        foreach ($labels as $label) {
            foreach ($entityManager->getRepository(MenuItem::class)->findBy(['label' => $label]) as $item) {
                if ($item instanceof MenuItem) {
                    $entityManager->remove($item);
                }
            }
        }

        $userId = $user->getId();
        if ($userId !== null) {
            $storedUser = $entityManager->find(User::class, $userId);
            if ($storedUser instanceof User) {
                foreach ($entityManager->getRepository(AuditLog::class)->findBy(['actor' => $storedUser]) as $entry) {
                    $entityManager->remove($entry);
                }
                $entityManager->remove($storedUser);
            }
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @param list<string> $permissions
     */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('menu-security-'.$label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Menu security '.$label)
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
