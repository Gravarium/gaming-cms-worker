<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class AdminCategoryManagementSecurityTest extends WebTestCase
{
    public function testAnonymousAndUnauthorizedUsersCannotAccessCategoryManager(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/categories');

        self::assertResponseRedirects('/login');

        $user = $this->createUser($client, 'reader', []);
        $client->loginUser($user);
        $client->request('GET', '/admin/categories');

        self::assertResponseStatusCodeSame(403);
    }

    public function testContentManagerCanCreateCategoryAndDeleteRequiresCsrf(): void
    {
        $client = static::createClient();
        $manager = $this->createUser($client, 'manager', [CmsPermission::CONTENT]);
        $client->loginUser($manager);

        $name = 'Community '.bin2hex(random_bytes(5));
        $crawler = $client->request('GET', '/admin/categories/new');
        $form = $crawler->selectButton('Speichern')->form([
            'category[name]' => $name,
            'category[description]' => 'Gaming community',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/admin/categories');
        $repository = $client->getContainer()->get(CategoryRepository::class);
        $category = $repository->findOneBy(['name' => $name]);
        self::assertInstanceOf(Category::class, $category);
        self::assertNotSame('', $category->getSlug());
        $id = $category->getId();
        self::assertNotNull($id);

        $client->request('POST', '/admin/categories/'.$id.'/delete');
        self::assertResponseStatusCodeSame(403);
        self::assertInstanceOf(Category::class, $repository->find($id));

        $crawler = $client->request('GET', '/admin/categories');
        $tokenField = $crawler->filter('form[action="/admin/categories/'.$id.'/delete"] input[name="_token"]');
        self::assertCount(1, $tokenField);
        $token = (string) $tokenField->attr('value');
        self::assertNotSame('', $token);

        $client->request('POST', '/admin/categories/'.$id.'/delete', ['_token' => $token]);

        self::assertResponseRedirects('/admin/categories');
        $this->entityManager($client)->clear();
        self::assertNull($repository->find($id));
    }

    public function testEditingCategoryToParentItselfIsRejectedWithoutChangingHierarchy(): void
    {
        $client = static::createClient();
        $manager = $this->createUser($client, 'hierarchy-manager', [CmsPermission::CONTENT]);
        $suffix = bin2hex(random_bytes(5));
        $parent = (new Category())
            ->setName('Parent '.$suffix)
            ->setSlug('parent-'.$suffix);
        $child = (new Category())
            ->setName('Child '.$suffix)
            ->setSlug('child-'.$suffix)
            ->setParent($parent);
        $this->entityManager($client)->persist($parent);
        $this->entityManager($client)->persist($child);
        $this->entityManager($client)->flush();
        $parentId = $parent->getId();
        $childId = $child->getId();
        self::assertNotNull($parentId);
        self::assertNotNull($childId);
        $client->loginUser($manager);

        $crawler = $client->request('GET', '/admin/categories/'.$childId.'/edit');
        $form = $crawler->selectButton('Speichern')->form([
            'category[name]' => $child->getName(),
            'category[parent]' => (string) $childId,
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Zyklus enthalten.');

        $this->entityManager($client)->clear();
        $stored = $this->entityManager($client)->getRepository(Category::class)->find($childId);
        self::assertInstanceOf(Category::class, $stored);
        self::assertSame($parentId, $stored->getParent()?->getId());
    }

    /** @param list<string> $permissions */
    private function createUser(KernelBrowser $client, string $label, array $permissions): User
    {
        $user = (new User())
            ->setEmail('category-'.$label.'-'.bin2hex(random_bytes(5)).'@example.test')
            ->setDisplayName('Category '.$label)
            ->setPassword('unused-test-hash')
            ->setPermissions($permissions);
        $this->entityManager($client)->persist($user);
        $this->entityManager($client)->flush();

        return $user;
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
