<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PublicCategoryDirectoryPaginationTest extends WebTestCase
{
    public function testDirectoryShowsStableTwentyCategoryPagesAndValidatesPageInputs(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(5));
        $categoryIds = [];

        try {
            for ($number = 1; $number <= 21; ++$number) {
                $category = (new Category())
                    ->setName(sprintf('Paginated %s %02d', $suffix, $number))
                    ->setSlug(sprintf('paginated-%s-%02d', $suffix, $number));
                $entityManager->persist($category);
            }
            $entityManager->flush();

            /** @var list<Category> $orderedCategories */
            $orderedCategories = $entityManager->getRepository(Category::class)->findBy([], ['name' => 'ASC', 'id' => 'ASC']);
            $expectedNames = array_map(static fn (Category $category): string => $category->getDisplayName(), $orderedCategories);
            $expectedPages = max(1, (int) ceil(count($expectedNames) / 20));
            self::assertGreaterThanOrEqual(2, $expectedPages);
            foreach ($orderedCategories as $category) {
                $id = $category->getId();
                self::assertNotNull($id);
                if (str_starts_with($category->getSlug(), 'paginated-'.$suffix.'-')) {
                    $categoryIds[] = $id;
                }
            }

            $crawler = $client->request('GET', '/news/categories');
            self::assertResponseIsSuccessful();
            self::assertSame(array_slice($expectedNames, 0, 20), $this->categoryNames($crawler));
            self::assertSelectorTextContains('.pagination', 'Seite 1 von '.$expectedPages);
            self::assertSelectorExists('nav[aria-label="Kategorienavigation"] a[rel="next"][href="/news/categories?page=2"]');

            $crawler = $client->request('GET', '/news/categories?page=2');
            self::assertResponseIsSuccessful();
            self::assertSame(array_slice($expectedNames, 20, 20), $this->categoryNames($crawler));
            self::assertSelectorTextContains('.pagination', 'Seite 2 von '.$expectedPages);
            self::assertSelectorExists('nav[aria-label="Kategorienavigation"] a[rel="prev"][href="/news/categories?page=1"]');

            foreach (['page=', 'page=0', 'page=-1', 'page=abc', 'page=1.5', 'page=01', 'page[]=1'] as $query) {
                $client->request('GET', '/news/categories?'.$query);
                self::assertResponseStatusCodeSame(400, 'Expected malformed query to be rejected: '.$query);
            }

            $client->request('GET', '/news/categories?page=10001');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/news/categories?page='.($expectedPages + 1));
            self::assertResponseStatusCodeSame(404);
        } finally {
            $entityManager->clear();
            foreach ($categoryIds as $id) {
                $category = $entityManager->find(Category::class, $id);
                if ($category instanceof Category) {
                    $entityManager->remove($category);
                }
            }
            $entityManager->flush();
            $entityManager->clear();
        }
    }

    /** @return list<string> */
    private function categoryNames(Crawler $crawler): array
    {
        return $crawler->filter('.news-grid article h2')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
    }
}
