<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\ContentEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicPageCategoryArchiveTest extends WebTestCase
{
    public function testDirectoryAndArchiveExposeOnlyPublishedListedPages(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $suffix = bin2hex(random_bytes(5));
        $authorEmail = 'wcp551-controller-'.$suffix.'@example.test';
        $categorySlugs = ['wcp551-parent-'.$suffix, 'wcp551-pages-'.$suffix, 'wcp551-hidden-'.$suffix];
        $entrySlugs = [
            'wcp551-public-a-'.$suffix,
            'wcp551-public-b-'.$suffix,
            'wcp551-draft-'.$suffix,
            'wcp551-future-'.$suffix,
            'wcp551-unlisted-'.$suffix,
            'wcp551-ended-'.$suffix,
            'wcp551-news-'.$suffix,
            'wcp551-hidden-only-'.$suffix,
        ];

        try {
            $author = $this->createAuthor($entityManager, $authorEmail);
            $parent = (new Category())
                ->setName('WCP551 Page guides '.$suffix)
                ->setSlug($categorySlugs[0]);
            $category = (new Category())
                ->setName('Builds & Walkthroughs <script>')
                ->setSlug($categorySlugs[1])
                ->setDescription('Published page category & description.')
                ->setParent($parent);
            $hiddenCategory = (new Category())
                ->setName('Hidden category '.$suffix)
                ->setSlug($categorySlugs[2]);
            foreach ([$parent, $category, $hiddenCategory] as $item) {
                $entityManager->persist($item);
            }

            $this->persistPage($entityManager, $author, $category, $entrySlugs[0], 'WCP551 Public page A '.$suffix, ContentEntry::STATUS_PUBLISHED, new \DateTimeImmutable('-2 days'));
            $this->persistPage(
                $entityManager,
                $author,
                $category,
                $entrySlugs[1],
                'WCP551 Public page B '.$suffix,
                ContentEntry::STATUS_PUBLISHED,
                new \DateTimeImmutable('-1 day'),
                scheduledUnpublishAt: new \DateTimeImmutable('+1 day'),
            );
            $this->persistPage($entityManager, $author, $category, $entrySlugs[2], 'WCP551 Draft page '.$suffix, ContentEntry::STATUS_DRAFT, null);
            $this->persistPage($entityManager, $author, $category, $entrySlugs[3], 'WCP551 Future page '.$suffix, ContentEntry::STATUS_PUBLISHED, new \DateTimeImmutable('+1 day'));
            $this->persistPage($entityManager, $author, $category, $entrySlugs[4], 'WCP551 Unlisted page '.$suffix, ContentEntry::STATUS_PUBLISHED, new \DateTimeImmutable('-1 day'), unlisted: true);
            $this->persistPage($entityManager, $author, $category, $entrySlugs[5], 'WCP551 Ended page '.$suffix, ContentEntry::STATUS_PUBLISHED, new \DateTimeImmutable('-2 days'), scheduledUnpublishAt: new \DateTimeImmutable('-1 hour'));
            $this->persistPage($entityManager, $author, $category, $entrySlugs[6], 'WCP551 News entry '.$suffix, ContentEntry::STATUS_PUBLISHED, new \DateTimeImmutable('-1 day'), type: ContentEntry::TYPE_NEWS);
            $this->persistPage($entityManager, $author, $hiddenCategory, $entrySlugs[7], 'WCP551 Hidden-only page '.$suffix, ContentEntry::STATUS_DRAFT, null);
            $entityManager->flush();

            $client->request('GET', '/pages/categories');
            self::assertResponseIsSuccessful();
            $directory = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('Builds &amp; Walkthroughs &lt;script&gt;', $directory);
            self::assertStringContainsString('2 veröffentlichte Seiten', $directory);
            self::assertStringNotContainsString('Hidden category '.$suffix, $directory);
            self::assertStringNotContainsString('<script>', $directory);

            $client->request('GET', '/pages/category/'.$category->getSlug());
            self::assertResponseIsSuccessful();
            $archive = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('WCP551 Public page A '.$suffix, $archive);
            self::assertStringContainsString('WCP551 Public page B '.$suffix, $archive);
            self::assertStringContainsString('/page/'.$entrySlugs[0], $archive);
            self::assertStringContainsString('/page/'.$entrySlugs[1], $archive);
            foreach (array_slice($entrySlugs, 2) as $hiddenSlug) {
                $hiddenEntry = $entityManager->getRepository(ContentEntry::class)->findOneBy(['slug' => $hiddenSlug]);
                self::assertInstanceOf(ContentEntry::class, $hiddenEntry);
                self::assertStringNotContainsString($hiddenEntry->getTitle(), $archive);
            }

            $client->request('GET', '/pages/category/'.$hiddenCategory->getSlug());
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/pages/category/missing-wcp551-category-'.$suffix);
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($entityManager, $entrySlugs, $categorySlugs, $authorEmail);
        }
    }

    public function testArchivePaginatesInPinnedPublishedAndIdOrder(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $suffix = bin2hex(random_bytes(5));
        $authorEmail = 'wcp551-pages-'.$suffix.'@example.test';
        $categorySlug = 'wcp551-paginated-'.$suffix;
        $entrySlugs = ['wcp551-pinned-'.$suffix];
        for ($number = 1; $number <= 21; ++$number) {
            $entrySlugs[] = sprintf('wcp551-page-%02d-%s', $number, $suffix);
        }

        try {
            $author = $this->createAuthor($entityManager, $authorEmail);
            $category = (new Category())
                ->setName('WCP551 Ordered pages '.$suffix)
                ->setSlug($categorySlug);
            $entityManager->persist($category);
            $this->persistPage(
                $entityManager,
                $author,
                $category,
                $entrySlugs[0],
                'WCP551 Pinned oldest '.$suffix,
                ContentEntry::STATUS_PUBLISHED,
                new \DateTimeImmutable('-10 days'),
                pinned: true,
            );
            for ($number = 1; $number <= 21; ++$number) {
                $slug = $entrySlugs[$number];
                $this->persistPage(
                    $entityManager,
                    $author,
                    $category,
                    $slug,
                    sprintf('WCP551 Ordinary page %02d %s', $number, $suffix),
                    ContentEntry::STATUS_PUBLISHED,
                    new \DateTimeImmutable('-1 day'),
                );
            }
            $entityManager->flush();

            $client->request('GET', '/pages/category/'.$categorySlug);
            self::assertResponseIsSuccessful();
            $firstPage = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('WCP551 Pinned oldest '.$suffix, $firstPage);
            self::assertStringContainsString('WCP551 Ordinary page 21 '.$suffix, $firstPage);
            self::assertStringNotContainsString('WCP551 Ordinary page 02 '.$suffix, $firstPage);
            self::assertLessThan(strpos($firstPage, 'WCP551 Ordinary page 21 '.$suffix), strpos($firstPage, 'WCP551 Pinned oldest '.$suffix));
            self::assertLessThan(strpos($firstPage, 'WCP551 Ordinary page 03 '.$suffix), strpos($firstPage, 'WCP551 Ordinary page 21 '.$suffix));

            $client->request('GET', '/pages/category/'.$categorySlug.'?page=2');
            self::assertResponseIsSuccessful();
            $secondPage = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('WCP551 Ordinary page 02 '.$suffix, $secondPage);
            self::assertStringContainsString('WCP551 Ordinary page 01 '.$suffix, $secondPage);
            self::assertStringNotContainsString('WCP551 Ordinary page 03 '.$suffix, $secondPage);

            $client->request('GET', '/pages/category/'.$categorySlug.'?page=3');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanup($entityManager, $entrySlugs, [$categorySlug], $authorEmail);
        }
    }

    /**
     * @param list<string> $entrySlugs
     * @param list<string> $categorySlugs
     */
    private function cleanup(EntityManagerInterface $entityManager, array $entrySlugs, array $categorySlugs, string $authorEmail): void
    {
        foreach ($entrySlugs as $slug) {
            $entry = $entityManager->getRepository(ContentEntry::class)->findOneBy(['slug' => $slug]);
            if ($entry instanceof ContentEntry) {
                $entityManager->remove($entry);
            }
        }
        foreach (array_reverse($categorySlugs) as $slug) {
            $category = $entityManager->getRepository(Category::class)->findOneBy(['slug' => $slug]);
            if ($category instanceof Category) {
                $entityManager->remove($category);
            }
        }
        $author = $entityManager->getRepository(User::class)->findOneBy(['email' => $authorEmail]);
        if ($author instanceof User) {
            $entityManager->remove($author);
        }
        $entityManager->flush();
    }

    private function persistPage(
        EntityManagerInterface $entityManager,
        User $author,
        Category $category,
        string $slug,
        string $title,
        string $status,
        ?\DateTimeImmutable $publishedAt,
        bool $unlisted = false,
        ?\DateTimeImmutable $scheduledUnpublishAt = null,
        string $type = ContentEntry::TYPE_PAGE,
        bool $pinned = false,
    ): void {
        $entry = (new ContentEntry())
            ->setType($type)
            ->setTitle($title)
            ->setSlug($slug)
            ->setAuthor($author)
            ->setCategory($category)
            ->setStatus($status)
            ->setPublishedAt($publishedAt)
            ->setUnlisted($unlisted)
            ->setScheduledUnpublishAt($scheduledUnpublishAt)
            ->setPinned($pinned);
        $entityManager->persist($entry);
    }

    private function createAuthor(EntityManagerInterface $entityManager, string $email): User
    {
        $author = (new User())
            ->setEmail($email)
            ->setDisplayName('WCP551 page archive test');
        $entityManager->persist($author);

        return $author;
    }

    private function entityManager(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
