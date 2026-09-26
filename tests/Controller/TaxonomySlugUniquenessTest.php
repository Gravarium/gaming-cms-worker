<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\ContentTag;
use App\Entity\User;
use App\Security\CmsPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\String\Slugger\SluggerInterface;

final class TaxonomySlugUniquenessTest extends WebTestCase
{
    public function testLongExpandingNamesStayBoundedAndUniqueOnCreateAndEdit(): void
    {
        $client = static::createClient();
        $suffix = bin2hex(random_bytes(4));
        $name = $suffix.str_repeat('ß', 92);
        self::assertSame(100, mb_strlen($name, 'UTF-8'));

        $slugger = $client->getContainer()->get(SluggerInterface::class);
        self::assertInstanceOf(SluggerInterface::class, $slugger);
        self::assertGreaterThan(120, strlen($slugger->slug($name)->toString()));

        $user = (new User())
            ->setEmail('taxonomy-slug-'.$suffix.'@example.test')
            ->setDisplayName('Taxonomy slug test')
            ->setPermissions([CmsPermission::ACCESS, CmsPermission::CONTENT])
            ->verifyEmail();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
        $client->loginUser($user);

        $this->submitName($client, '/admin/categories/new', 'category', $name, '/admin/categories');
        $category = $entityManager->getRepository(Category::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Category::class, $category);
        $categoryId = $category->getId();
        self::assertNotNull($categoryId);
        $this->submitName($client, '/admin/categories/'.$categoryId.'/edit', 'category', $name, '/admin/categories');
        $this->submitName($client, '/admin/categories/new', 'category', $name, '/admin/categories');

        $this->submitName($client, '/admin/content/tags/new', 'content_tag', $name, '/admin/content/tags');
        $tag = $entityManager->getRepository(ContentTag::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(ContentTag::class, $tag);
        $tagId = $tag->getId();
        self::assertNotNull($tagId);
        $this->submitName($client, '/admin/content/tags/'.$tagId.'/edit', 'content_tag', $name, '/admin/content/tags');
        $this->submitName($client, '/admin/content/tags/new', 'content_tag', $name, '/admin/content/tags');

        $entityManager->clear();
        $categories = $entityManager->getRepository(Category::class)->findBy(['name' => $name], ['id' => 'ASC']);
        $categorySlugs = array_map(static fn (Category $item): string => $item->getSlug(), $categories);
        $this->assertTwoBoundedUniqueSlugs($categorySlugs);

        $tags = $entityManager->getRepository(ContentTag::class)->findBy(['name' => $name], ['id' => 'ASC']);
        $tagSlugs = array_map(static fn (ContentTag $item): string => $item->getSlug(), $tags);
        $this->assertTwoBoundedUniqueSlugs($tagSlugs);
    }

    /** @param list<string> $slugs */
    private function assertTwoBoundedUniqueSlugs(array $slugs): void
    {
        self::assertCount(2, $slugs);
        self::assertCount(2, array_unique($slugs));
        self::assertContains(true, array_map(static fn (string $slug): bool => str_ends_with($slug, '-2'), $slugs));

        foreach ($slugs as $slug) {
            self::assertSame(120, strlen($slug));
            self::assertMatchesRegularExpression('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug);
        }
    }

    private function submitName(
        KernelBrowser $client,
        string $path,
        string $formName,
        string $name,
        string $redirectPath,
    ): void {
        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Speichern')->form([$formName.'[name]' => $name]);
        $client->submit($form);
        self::assertResponseRedirects($redirectPath);
    }
}
