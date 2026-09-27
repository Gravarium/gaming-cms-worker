<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Category;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminCategoryWriteSecurityTest extends WebTestCase
{
    public function testCategoryRoutesRequireContentPermission(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'category-denied', [CmsPermission::VIDEO]);
        $name = 'Denied category '.bin2hex(random_bytes(4));
        $category = $this->createCategory($client, $name);

        try {
            $client->loginUser($user);

            $client->request('GET', '/admin/categories');
            self::assertResponseStatusCodeSame(403);

            $client->request('GET', '/admin/categories/new');
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/admin/categories/new', [
                'category' => ['name' => 'Unauthorized category', 'parent' => '', 'description' => ''],
            ]);
            self::assertResponseStatusCodeSame(403);

            $categoryId = $category->getId();
            self::assertNotNull($categoryId);
            $client->request('POST', '/admin/categories/'.$categoryId.'/delete');
            self::assertResponseStatusCodeSame(403);

            $stored = $this->findCategory($client, $name);
            self::assertInstanceOf(Category::class, $stored);
            self::assertSame($name, $stored->getName());
            self::assertSame([], $this->auditEntries($client, $user));
        } finally {
            $this->cleanup($client, $user, [$name]);
        }
    }

    public function testCategoryCreateAndDeleteRequireTheirRenderedCsrfTokens(): void
    {
        $client = static::createClient();
        $user = $this->createUser($client, 'category-csrf', [CmsPermission::CONTENT]);
        $suffix = bin2hex(random_bytes(4));
        $categoryName = 'wcp468 category '.$suffix;
        $categorySlug = 'wcp468-category-'.$suffix;
        $rejectedNames = [
            'wcp468 rejected missing '.$suffix,
            'wcp468 rejected invalid '.$suffix,
        ];
        $cleanupNames = [...$rejectedNames, $categoryName];

        try {
            $client->loginUser($user);

            foreach ([
                ['mode' => 'missing', 'name' => $rejectedNames[0]],
                ['mode' => 'invalid', 'name' => $rejectedNames[1]],
            ] as $case) {
                $values = $this->renderedFormValues($client, $case['name'], $case['mode']);
                $client->request('POST', '/admin/categories/new', $values);

                self::assertResponseStatusCodeSame(422);
                self::assertNull($this->findCategory($client, $case['name']));
                self::assertSame([], $this->auditEntries($client, $user));
            }

            $values = $this->renderedFormValues($client, $categoryName, 'valid');
            $client->request('POST', '/admin/categories/new', $values);
            self::assertResponseRedirects('/admin/categories');

            $category = $this->findCategory($client, $categoryName);
            self::assertInstanceOf(Category::class, $category);
            self::assertSame($categorySlug, $category->getSlug());
            $categoryId = $category->getId();
            self::assertNotNull($categoryId);

            $logs = $this->auditEntries($client, $user);
            self::assertCount(1, $logs);
            self::assertSame('content_category.save', $logs[0]->getAction());

            $deletePath = '/admin/categories/'.$categoryId.'/delete';
            foreach ([[], ['_token' => 'invalid']] as $parameters) {
                $client->request('POST', $deletePath, $parameters);

                self::assertResponseStatusCodeSame(403);
                self::assertInstanceOf(Category::class, $this->findCategory($client, $categoryName));
                self::assertCount(1, $this->auditEntries($client, $user));
            }

            $crawler = $client->request('GET', '/admin/categories');
            self::assertResponseIsSuccessful();
            $deleteToken = (string) $crawler
                ->filter('form[action="'.$deletePath.'"] input[name="_token"]')
                ->attr('value');
            self::assertNotSame('', $deleteToken);

            $client->request('POST', $deletePath, ['_token' => $deleteToken]);

            self::assertResponseRedirects('/admin/categories');
            self::assertNull($this->findCategory($client, $categoryName));
            $actions = array_map(static fn (AuditLog $log): string => $log->getAction(), $this->auditEntries($client, $user));
            self::assertContains('content_category.save', $actions);
            self::assertContains('content_category.delete', $actions);
        } finally {
            $this->cleanup($client, $user, $cleanupNames);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function renderedFormValues(KernelBrowser $client, string $name, string $csrfMode): array
    {
        $crawler = $client->request('GET', '/admin/categories/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Speichern')->form();
        $formName = $form->getName();
        self::assertNotSame('', $formName);

        $values = $form->getPhpValues();
        $formValues = $values[$formName] ?? null;
        self::assertIsArray($formValues);
        self::assertArrayHasKey('_token', $formValues);
        self::assertNotSame('', (string) $formValues['_token']);
        $formValues['name'] = $name;

        if ($csrfMode === 'missing') {
            unset($formValues['_token']);
        } elseif ($csrfMode === 'invalid') {
            $formValues['_token'] = 'invalid';
        }

        $values[$formName] = $formValues;

        return $values;
    }

    private function createCategory(KernelBrowser $client, string $name): Category
    {
        $category = (new Category())
            ->setName($name)
            ->setSlug('fixture-'.bin2hex(random_bytes(5)));
        $this->entityManager($client)->persist($category);
        $this->entityManager($client)->flush();

        return $category;
    }

    private function findCategory(KernelBrowser $client, string $name): ?Category
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $category = $entityManager->getRepository(Category::class)->findOneBy(['name' => $name]);

        return $category instanceof Category ? $category : null;
    }

    /**
     * @return list<AuditLog>
     */
    private function auditEntries(KernelBrowser $client, User $user): array
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();
        $userId = $user->getId();
        self::assertNotNull($userId);

        $storedUser = $entityManager->find(User::class, $userId);
        self::assertInstanceOf(User::class, $storedUser);
        $entries = $entityManager->getRepository(AuditLog::class)->findBy(['actor' => $storedUser]);

        return array_values(array_filter(
            $entries,
            static fn (object $entry): bool => $entry instanceof AuditLog,
        ));
    }

    /**
     * @param list<string> $categoryNames
     */
    private function cleanup(KernelBrowser $client, User $user, array $categoryNames): void
    {
        $entityManager = $this->entityManager($client);
        $entityManager->clear();

        foreach ($categoryNames as $name) {
            foreach ($entityManager->getRepository(Category::class)->findBy(['name' => $name]) as $category) {
                if ($category instanceof Category) {
                    $entityManager->remove($category);
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
            ->setEmail('category-security-'.$label.'-'.bin2hex(random_bytes(6)).'@example.test')
            ->setDisplayName('Category security '.$label)
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
