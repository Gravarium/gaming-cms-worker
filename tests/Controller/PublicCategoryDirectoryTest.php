<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\CmsModuleState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicCategoryDirectoryTest extends WebTestCase
{
    public function testAnonymousDirectoryListsHierarchicalCategoriesAndExistingArchiveLinks(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $suffix = bin2hex(random_bytes(5));
        $parentSlug = 'category-directory-parent-'.$suffix;
        $childSlug = 'category-directory-child-'.$suffix;
        $otherSlug = 'category-directory-other-'.$suffix;

        $parent = (new Category())
            ->setName('Alpha '.$suffix)
            ->setSlug($parentSlug)
            ->setDescription('Parent category description.');
        $child = (new Category())
            ->setName('Beta '.$suffix)
            ->setSlug($childSlug)
            ->setParent($parent)
            ->setDescription('Child category description.');
        $other = (new Category())
            ->setName('Zeta '.$suffix)
            ->setSlug($otherSlug);
        $em->persist($parent);
        $em->persist($child);
        $em->persist($other);
        $em->flush();
        $categoryIds = [$parent->getId(), $child->getId(), $other->getId()];

        try {
            $client->request('GET', '/news/categories');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Kategorien');
            self::assertSelectorTextContains('body', 'Alpha '.$suffix);
            self::assertSelectorTextContains('body', 'Alpha '.$suffix.' / Beta '.$suffix);
            self::assertSelectorTextContains('body', 'Parent category description.');
            self::assertSelectorExists('a[href="/news/category/'.$parentSlug.'"]');
            self::assertSelectorExists('a[href="/news/category/'.$childSlug.'"]');
            self::assertSelectorExists('a[href="/news/category/'.$otherSlug.'"]');
            self::assertSelectorNotExists('form');

            $html = (string) $client->getResponse()->getContent();
            self::assertLessThan(
                strpos($html, 'Zeta '.$suffix),
                strpos($html, 'Alpha '.$suffix),
                'Categories should be rendered in deterministic name order.',
            );

            $client->request('POST', '/news/categories');
            self::assertResponseStatusCodeSame(405);
        } finally {
            $this->removeCategories($client, $categoryIds);
        }
    }

    public function testDisabledContentModuleReturnsNotFoundWithoutCategoryContents(): void
    {
        $client = static::createClient();
        $em = $this->em($client);
        $suffix = bin2hex(random_bytes(5));
        $category = (new Category())
            ->setName('Hidden directory '.$suffix)
            ->setSlug('hidden-category-directory-'.$suffix);
        $em->persist($category);
        $em->flush();
        $categoryId = $category->getId();

        $state = $em->getRepository(CmsModuleState::class)->find('content');
        $previousEnabled = $state?->isEnabled();
        if ($state === null) {
            $state = (new CmsModuleState())
                ->setModuleKey('content')
                ->updateVersion('1.0.0');
            $em->persist($state);
        }
        $state->setEnabled(false);
        $em->flush();

        try {
            $client->request('GET', '/news/categories');

            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('Hidden directory '.$suffix, (string) $client->getResponse()->getContent());
        } finally {
            $this->restoreContentModule($client, $previousEnabled);
            $this->removeCategories($client, [$categoryId]);
        }
    }

    /** @param list<int|null> $ids */
    private function removeCategories(KernelBrowser $client, array $ids): void
    {
        $em = $this->em($client);
        $em->clear();
        foreach (array_reverse(array_values(array_filter($ids, static fn (?int $id): bool => $id !== null))) as $id) {
            $category = $em->find(Category::class, $id);
            if ($category !== null) {
                $em->remove($category);
            }
        }
        $em->flush();
        $em->clear();
    }

    private function restoreContentModule(KernelBrowser $client, ?bool $previousEnabled): void
    {
        $em = $this->em($client);
        $em->clear();
        $state = $em->getRepository(CmsModuleState::class)->find('content');
        if ($previousEnabled === null) {
            if ($state !== null) {
                $em->remove($state);
            }
        } elseif ($state !== null) {
            $state->setEnabled($previousEnabled);
        }
        $em->flush();
        $em->clear();
    }

    private function em(KernelBrowser $client): EntityManagerInterface
    {
        return $client->getContainer()->get(EntityManagerInterface::class);
    }
}
