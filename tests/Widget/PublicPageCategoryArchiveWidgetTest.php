<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Entity\Category;
use App\Entity\CmsModuleState;
use App\Entity\ContentEntry;
use App\Entity\PageLayout;
use App\Entity\User;
use App\Layout\LayoutValidator;
use App\PageCategoryArchive\PublicPageCategoryArchiveQuery;
use App\Theme\ThemeRegistry;
use App\Widget\PublicPageCategoryArchiveWidgetProvider;
use App\Widget\WidgetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class PublicPageCategoryArchiveWidgetTest extends WebTestCase
{
    public function testPageBuilderWidgetListsOnlyVisiblePageCategoriesAndCapsTheCount(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager($client);
        $snapshot = $this->snapshot($entityManager);
        $suffix = bin2hex(random_bytes(5));
        $authorEmail = 'wcp551-widget-'.$suffix.'@example.test';
        $categorySlugs = [];
        $entrySlugs = [];

        try {
            $this->enableContent($entityManager);
            $author = (new User())
                ->setEmail($authorEmail)
                ->setDisplayName('WCP551 widget test');
            $entityManager->persist($author);

            for ($number = 1; $number <= 21; ++$number) {
                $categorySlug = sprintf('wcp551-widget-%02d-%s', $number, $suffix);
                $pageSlug = sprintf('wcp551-widget-page-%02d-%s', $number, $suffix);
                $categorySlugs[] = $categorySlug;
                $entrySlugs[] = $pageSlug;
                $category = (new Category())
                    ->setName(sprintf('WCP551 Category %02d %s', $number, $suffix))
                    ->setSlug($categorySlug);
                $page = (new ContentEntry())
                    ->setType(ContentEntry::TYPE_PAGE)
                    ->setTitle(sprintf('WCP551 Widget page %02d %s', $number, $suffix))
                    ->setSlug($pageSlug)
                    ->setAuthor($author)
                    ->setCategory($category)
                    ->setStatus(ContentEntry::STATUS_PUBLISHED)
                    ->setPublishedAt(new \DateTimeImmutable('-1 day'));
                $entityManager->persist($category);
                $entityManager->persist($page);
            }

            $hiddenCategorySlug = 'wcp551-widget-hidden-'.$suffix;
            $hiddenPageSlug = 'wcp551-widget-hidden-page-'.$suffix;
            $categorySlugs[] = $hiddenCategorySlug;
            $entrySlugs[] = $hiddenPageSlug;
            $hiddenCategory = (new Category())
                ->setName('WCP551 Hidden category '.$suffix)
                ->setSlug($hiddenCategorySlug);
            $hiddenPage = (new ContentEntry())
                ->setType(ContentEntry::TYPE_PAGE)
                ->setTitle('WCP551 hidden draft '.$suffix)
                ->setSlug($hiddenPageSlug)
                ->setAuthor($author)
                ->setCategory($hiddenCategory)
                ->setStatus(ContentEntry::STATUS_DRAFT);
            $entityManager->persist($hiddenCategory);
            $entityManager->persist($hiddenPage);
            $entityManager->flush();

            $registry = $client->getContainer()->get(WidgetRegistry::class);
            $definition = $registry->get(PublicPageCategoryArchiveWidgetProvider::KEY);
            self::assertNotNull($definition);
            self::assertSame('content', $definition->module);
            self::assertSame('widget/page_categories.html.twig', $definition->template);
            self::assertTrue($registry->available(PublicPageCategoryArchiveWidgetProvider::KEY));

            $data = $registry->data(PublicPageCategoryArchiveWidgetProvider::KEY, ['count' => 500]);
            self::assertCount(PublicPageCategoryArchiveWidgetProvider::MAX_ITEMS, $data['categories']);
            self::assertSame(
                'WCP551 Category 01 '.$suffix,
                $data['categories'][0]['category']->getName(),
            );
            self::assertSame(
                'WCP551 Category 12 '.$suffix,
                $data['categories'][11]['category']->getName(),
            );

            $query = $client->getContainer()->get(PublicPageCategoryArchiveQuery::class);
            self::assertCount(21, $query->findCategoriesWithPublicPages(100));
            self::assertCount(1, $query->findCategoriesWithPublicPages(20, 20));

            $emptyMarkup = $client->getContainer()->get(Environment::class)->render(
                'widget/page_categories.html.twig',
                ['config' => [], 'data' => ['categories' => []]],
            );
            self::assertStringContainsString('Zurzeit gibt es keine veröffentlichten Seitenkategorien.', $emptyMarkup);
            self::assertStringNotContainsString('<script>', $emptyMarkup);

            $this->saveHomeLayout($client, 12);
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.widget-content-page_categories');
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('WCP551 Category 01 '.$suffix, $html);
            self::assertStringContainsString('WCP551 Category 12 '.$suffix, $html);
            self::assertStringNotContainsString('WCP551 Category 13 '.$suffix, $html);
            self::assertStringNotContainsString('WCP551 Hidden category '.$suffix, $html);
            self::assertStringContainsString('href="/pages/categories"', $html);
            self::assertStringContainsString('/pages/category/wcp551-widget-01-'.$suffix, $html);

            $state = $entityManager->find(CmsModuleState::class, 'content');
            self::assertInstanceOf(CmsModuleState::class, $state);
            $state->setEnabled(false);
            $entityManager->flush();

            self::assertFalse($registry->available(PublicPageCategoryArchiveWidgetProvider::KEY));
            self::assertSame([], $registry->data(PublicPageCategoryArchiveWidgetProvider::KEY, ['count' => 12]));
            $client->request('GET', '/pages/categories');
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('.widget-content-page_categories');
        } finally {
            $this->restore($client, $snapshot, $entrySlugs, $categorySlugs, $authorEmail);
        }
    }

    /**
     * @return array{stateExists: bool, stateEnabled: ?bool, layoutExists: bool, layoutDocument: ?array}
     */
    private function snapshot(EntityManagerInterface $entityManager): array
    {
        $state = $entityManager->find(CmsModuleState::class, 'content');
        $layout = $entityManager->find(PageLayout::class, 'home');

        return [
            'stateExists' => $state instanceof CmsModuleState,
            'stateEnabled' => $state?->isEnabled(),
            'layoutExists' => $layout instanceof PageLayout,
            'layoutDocument' => $layout?->getDocument(),
        ];
    }

    private function enableContent(EntityManagerInterface $entityManager): void
    {
        $state = $entityManager->find(CmsModuleState::class, 'content');
        if (!$state instanceof CmsModuleState) {
            $state = (new CmsModuleState())
                ->setModuleKey('content')
                ->updateVersion('1.0.0');
        }
        $state->setEnabled(true);
        $entityManager->persist($state);
        $entityManager->flush();
    }

    private function saveHomeLayout(KernelBrowser $client, int $count): void
    {
        $container = $client->getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $validator = $container->get(LayoutValidator::class);
        $document = $validator->defaults('nebula')->toArray();
        $document['widgets'][] = [
            'id' => 'wcp551-page-categories',
            'type' => PublicPageCategoryArchiveWidgetProvider::KEY,
            'region' => $container->get(ThemeRegistry::class)->get('nebula')->fallbackRegion,
            'enabled' => true,
            'config' => ['count' => $count],
        ];
        $record = $entityManager->find(PageLayout::class, 'home') ?? new PageLayout('home');
        $record->replace($validator->validate($document)->toArray());
        $entityManager->persist($record);
        $entityManager->flush();
    }

    /**
     * @param array{stateExists: bool, stateEnabled: ?bool, layoutExists: bool, layoutDocument: ?array} $snapshot
     * @param list<string> $entrySlugs
     * @param list<string> $categorySlugs
     */
    private function restore(KernelBrowser $client, array $snapshot, array $entrySlugs, array $categorySlugs, string $authorEmail): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

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

        $layout = $entityManager->find(PageLayout::class, 'home');
        if ($snapshot['layoutExists']) {
            $layout ??= new PageLayout('home');
            $layout->replace($snapshot['layoutDocument'] ?? []);
            $entityManager->persist($layout);
        } elseif ($layout instanceof PageLayout) {
            $entityManager->remove($layout);
        }

        $state = $entityManager->find(CmsModuleState::class, 'content');
        if ($snapshot['stateExists'] && $state instanceof CmsModuleState && $snapshot['stateEnabled'] !== null) {
            $state->setEnabled($snapshot['stateEnabled']);
        } elseif (!$snapshot['stateExists'] && $state instanceof CmsModuleState) {
            $entityManager->remove($state);
        }

        $entityManager->flush();
    }

    private function entityManager(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
