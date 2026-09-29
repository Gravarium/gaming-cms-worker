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

final class AdminCategoryHierarchyListingTest extends WebTestCase
{
    public function testListingShowsHierarchyAndLinksToItsPublicArchive(): void
    {
        $client = static::createClient();
        $manager = $this->createUser($client, 'hierarchy-list', [CmsPermission::CONTENT]);
        $suffix = bin2hex(random_bytes(5));
        $rootName = 'Games '.$suffix;
        $childName = 'Reviews '.$suffix;
        $rootSlug = 'games-'.$suffix;
        $childSlug = 'reviews-'.$suffix;
        $root = (new Category())->setName($rootName)->setSlug($rootSlug);
        $child = (new Category())->setName($childName)->setSlug($childSlug)->setParent($root);
        $this->entityManager($client)->persist($root);
        $this->entityManager($client)->persist($child);
        $this->entityManager($client)->flush();
        $client->loginUser($manager);

        $crawler = $client->request('GET', '/admin/categories');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', $rootName.' / '.$childName);
        $publicLink = $crawler->filter('a[href="/news/category/'.$childSlug.'"]');
        self::assertCount(1, $publicLink);
        self::assertSame('Öffentliche Ansicht: '.$rootName.' / '.$childName, $publicLink->attr('aria-label'));

        $client->click($publicLink->link());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Kategorie: '.$rootName.' / '.$childName);
    }

    public function testDeleteRejectionShowsErrorAndPreservesParentAndChild(): void
    {
        $client = static::createClient();
        $manager = $this->createUser($client, 'blocked-delete', [CmsPermission::CONTENT]);
        $suffix = bin2hex(random_bytes(5));
        $root = (new Category())->setName('Root '.$suffix)->setSlug('root-'.$suffix);
        $child = (new Category())->setName('Child '.$suffix)->setSlug('child-'.$suffix)->setParent($root);
        $this->entityManager($client)->persist($root);
        $this->entityManager($client)->persist($child);
        $this->entityManager($client)->flush();
        $rootId = $root->getId();
        $childId = $child->getId();
        self::assertNotNull($rootId);
        self::assertNotNull($childId);
        $client->loginUser($manager);

        $crawler = $client->request('GET', '/admin/categories');
        $tokenSelector = 'form[action="/admin/categories/'.$rootId.'/delete"] input[name="_token"]';
        self::assertCount(1, $crawler->filter($tokenSelector));
        $token = (string) $crawler->filter($tokenSelector)->attr('value');
        self::assertNotSame('', $token);

        $client->request('POST', '/admin/categories/'.$rootId.'/delete', ['_token' => 'invalid-token']);

        self::assertResponseStatusCodeSame(403);
        self::assertInstanceOf(Category::class, $client->getContainer()->get(CategoryRepository::class)->find($rootId));

        $client->request('POST', '/admin/categories/'.$rootId.'/delete', ['_token' => $token]);

        self::assertResponseRedirects('/admin/categories');
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[role="alert"]');
        self::assertSelectorTextContains('body', 'Die Kategorie wird noch verwendet oder enthält Unterkategorien. Verschiebe diese zuerst.');
        $this->entityManager($client)->clear();
        $repository = $client->getContainer()->get(CategoryRepository::class);
        self::assertInstanceOf(Category::class, $repository->find($rootId));
        self::assertInstanceOf(Category::class, $repository->find($childId));
    }

    public function testListingStillRequiresContentManagementPermission(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/categories');

        self::assertResponseRedirects('/login');

        $reader = $this->createUser($client, 'category-reader', []);
        $client->loginUser($reader);
        $client->request('GET', '/admin/categories');

        self::assertResponseStatusCodeSame(403);
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
